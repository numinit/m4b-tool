<?php


namespace M4bTool\Command;

use Exception;
use FilesystemIterator;
use IteratorIterator;
use M4bTool\Audio\Tag;
use M4bTool\Audio\TagTransfer\Ffmetadata;
use M4bTool\Audio\TagTransfer\InputOptions;
use M4bTool\Audio\TagTransfer\OpenPackagingFormat;
use M4bTool\Audio\TagTransfer\TagTransferComposite;
use M4bTool\Chapter\ChapterHandler;
use M4bTool\Chapter\MetaReaderInterface;
use M4bTool\Audio\Chapter;
use M4bTool\Audio\Silence;
use M4bTool\Common\ConditionalFlags;
use M4bTool\Executables\Fdkaac;
use M4bTool\Executables\Ffmpeg;
use M4bTool\Executables\FileConverterOptions;
use M4bTool\Executables\Tasks\ConversionTask;
use M4bTool\Executables\Tasks\Pool;
use M4bTool\Filesystem\DirectoryLoader;
use M4bTool\Chapter\ChapterMarker;
use M4bTool\Filesystem\FileLoader;
use M4bTool\Parser\FfmetaDataParser;
use M4bTool\Parser\Mp4ChapsChapterParser;
use M4bTool\Parser\MusicBrainzChapterParser;
use M4bTool\Parser\SilenceParser;
use Psr\Cache\InvalidArgumentException;
use RecursiveDirectoryIterator;
use Sandreas\Strings\Format\FormatParser;
use Sandreas\Strings\Format\PlaceHolder;
use Sandreas\Time\TimeUnit;
use SplFileInfo;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class MergeCommand extends AbstractConversionCommand implements MetaReaderInterface
{

    const ARGUMENT_MORE_INPUT_FILES = "more-input-files";
    const OPTION_INCLUDE_EXTENSIONS = "include-extensions";
    const OPTION_MARK_TRACKS = "mark-tracks";
    const OPTION_AUTO_SPLIT_SECONDS = "auto-split-seconds";
    const OPTION_NO_CONVERSION = "no-conversion";
    const OPTION_BATCH_PATTERN = "batch-pattern";
    const OPTION_DRY_RUN = "dry-run";
    const OPTION_JOBS = "jobs";

    const OPTION_CHAPTER_NO_REINDEXING = "no-chapter-reindexing";
    const OPTION_CHAPTER_USE_FILENAMES = "use-filenames-as-chapters";

    const MAPPING_OPTIONS_PLACEHOLDERS = [
        self::OPTION_TAG_NAME => "n",
        self::OPTION_TAG_SORT_NAME => "N",
        self::OPTION_TAG_ALBUM => "m",
        self::OPTION_TAG_SORT_ALBUM => "M",
        self::OPTION_TAG_ARTIST => "a",
        self::OPTION_TAG_SORT_ARTIST => "A",
        self::OPTION_TAG_GENRE => "g",
        self::OPTION_TAG_WRITER => "w",
        self::OPTION_TAG_ALBUM_ARTIST => "t",
        self::OPTION_TAG_YEAR => "y",
        self::OPTION_TAG_DESCRIPTION => "d",
        self::OPTION_TAG_LONG_DESCRIPTION => "D",
        self::OPTION_TAG_COMMENT => "c",
        self::OPTION_TAG_COPYRIGHT => "C",
        self::OPTION_TAG_ENCODED_BY => "e",
        self::OPTION_TAG_SERIES => "s",
        self::OPTION_TAG_SERIES_PART => "p",

        // "c" => self::OPTION_TAG_COVER, // cover cannot be string
    ];

    const NORMALIZE_CHAPTER_OPTIONS = [
        'first-chapter-offset' => 0,
        'last-chapter-offset' => 0,
        'merge-similar' => false,
        'no-chapter-numbering' => false,
        'chapter-pattern' => "/^[^:]+[1-9][0-9]*:[\s]*(.*),.*[1-9][0-9]*[\s]*$/i",
        'chapter-remove-chars' => "„“”",
    ];

    protected $outputDirectory;

    protected $meta = [];
    /**
     * @var SplFileInfo[]
     */
    protected $filesToConvert = [];
    protected $filesToMerge = [];
    protected $sameFormatFiles = [];

    /** @var SplFileInfo */
    protected $outputFile;
    protected $sameFormatFileDirectory;


    /** @var Silence[] */
    protected $trackMarkerSilences = [];

    /** @var string[] */
    protected $alreadyProcessedBatchDirs = [];


    protected function configure()
    {
        parent::configure();

        $this->setDescription('Merges a set of files to one single file');
        $this->setHelp('Merges a set of files to one single file');

        // configure an argument
        $this->addArgument(static::ARGUMENT_MORE_INPUT_FILES, InputArgument::IS_ARRAY, 'Other Input files or folders');
        $this->addOption(static::OPTION_OUTPUT_FILE, static::OPTION_OUTPUT_FILE_SHORTCUT, InputOption::VALUE_REQUIRED, "output file");
        $this->addOption(static::OPTION_INCLUDE_EXTENSIONS, null, InputOption::VALUE_OPTIONAL, "comma separated list of file extensions to include (others are skipped)", "aac,alac,flac,m4a,m4b,mp3,oga,ogg,wav,wma,mp4");
        $this->addOption(static::OPTION_MUSICBRAINZ_ID, "m", InputOption::VALUE_REQUIRED, "musicbrainz id so load chapters from");
        $this->addOption(static::OPTION_NO_CONVERSION, null, InputOption::VALUE_NONE, "skip conversion (destination file uses same encoding as source - all encoding specific options will be ignored)");

        $this->addOption(static::OPTION_BATCH_PATTERN, null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, "multiple batch patterns that can be used to merge all audio books in a directory matching the given patterns (e.g. %a/%t for author/title) - parameter --output-file must be a directory", []);
        $this->addOption(static::OPTION_DRY_RUN, null, InputOption::VALUE_NONE, "perform a dry run without converting all the files in batch mode (requires --" . static::OPTION_BATCH_PATTERN . ")");
        $this->addOption(static::OPTION_JOBS, null, InputOption::VALUE_OPTIONAL, "Specifies the number of jobs (commands) to run simultaneously", 1);

        $this->addOption(static::OPTION_CHAPTER_USE_FILENAMES, null, InputOption::VALUE_NONE, "Use filenames for chapter titles instead of tag contents");
        $this->addOption(static::OPTION_CHAPTER_NO_REINDEXING, null, InputOption::VALUE_NONE, "Do not perform any reindexing for index-only chapter names (by default m4b-tool will try to detect index-only chapters like Chapter 1, Chapter 2 and reindex it with its numbers only)");

    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|void|null
     * @throws Exception
     * @throws InvalidArgumentException
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {

        try {

            $flags = new ConditionalFlags();
            $flags->insertIf(ChapterHandler::NO_REINDEXING, $input->getOption(static::OPTION_CHAPTER_NO_REINDEXING));
            $flags->insertIf(ChapterHandler::USE_FILENAMES, $input->getOption(static::OPTION_CHAPTER_USE_FILENAMES));

            $this->chapterHandler->setFlags($flags);

            $batchPatterns = $input->getOption(static::OPTION_BATCH_PATTERN);
            if ($this->isBatchMode($input)) {
                $this->ensureValidInputForBatchMode($input);

                $batchJobs = [];
                foreach ($batchPatterns as $batchPattern) {
                    $batchJobs = array_merge($batchJobs, $this->prepareBatchJobs(clone $input, clone $output, $batchPattern));
                }

                $this->processBatchJobs(clone $this, clone $output, $batchJobs);

            } else {
                $this->ensureValidInputForSingleFileMode($input);
                $this->processFiles($input, $output);
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            $this->debug("trace:", $e->getTraceAsString());
        }


    }

    private function isBatchMode(InputInterface $input)
    {
        return count($input->getOption(static::OPTION_BATCH_PATTERN));
    }

    /**
     * @param $input
     * @throws Exception
     */
    private function ensureValidInputForBatchMode(InputInterface $input)
    {
        $inputFile = new SplFileInfo($input->getArgument(static::ARGUMENT_INPUT));
        $inputFiles = $input->getArgument(static::ARGUMENT_MORE_INPUT_FILES);
        if (count($inputFiles) > 0 || !is_dir($inputFile)) {
            throw new Exception(sprintf("The use of --%s assumes that exactly one directory is processed - please provide a valid and existing directory", static::OPTION_BATCH_PATTERN));
        }

        $outputFile = new SplFileInfo($input->getOption(static::OPTION_OUTPUT_FILE));
        if ($outputFile->isFile()) {
            throw new Exception(sprintf("The use of --%s assumes that --%s is a directory", static::OPTION_BATCH_PATTERN, static::OPTION_OUTPUT_FILE));
        }
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param string $batchPattern
     * @return InputInterface[]
     * @throws Exception
     */
    private function prepareBatchJobs(InputInterface $input, OutputInterface $output, $batchPattern)
    {

        $this->initExecution($input, $output);
        $outputFile = new SplFileInfo($input->getOption(static::OPTION_OUTPUT_FILE));
        $this->ensureOutputFileIsNotEmpty($outputFile);

        $dirLoader = new DirectoryLoader();
        $currentBatchDirs = $dirLoader->load($input->getArgument(static::ARGUMENT_INPUT), $this->parseIncludeExtensions(["jpg", "jpeg", "png", "txt"]), $this->alreadyProcessedBatchDirs);
        $normalizedBatchPattern = $this->normalizeDirectory($batchPattern);

        $verifiedDirectories = [];
        foreach ($currentBatchDirs as $baseDir) {
            $placeHolders = static::createPlaceHoldersForOptions();
            $formatParser = new FormatParser(...$placeHolders);
            $patternDir = $this->normalizeDirectory($baseDir);
            if ($formatParser->parseFormat($normalizedBatchPattern, $patternDir)) {
                $verifiedDirectories[$baseDir] = $formatParser;
                $this->alreadyProcessedBatchDirs[] = $baseDir;

            }
        }

        $matchCount = count($verifiedDirectories);
        $this->notice(($matchCount === 1 ? "1 match" : $matchCount . " matches") . " for pattern " . $batchPattern);

        if ($matchCount > 0) {
            $this->notice("================================");
        }


        $batchJobs = [];
        foreach ($verifiedDirectories as $baseDir => $formatParser) {
            // clone input to work with current directory instead of existing data from an old directory
            $clonedInput = clone $input;
            $trimmedBatchPattern = $formatParser->trimSeparatorPrefix($batchPattern);

            $fileNamePart = rtrim($formatParser->format($trimmedBatchPattern), "\\/");

            // add a folder for name, if it is not a series
            $title = $formatParser->format("%n");
            $album = $formatParser->format("%m");
            $m4bFileName = $title ? $title : $album;
            if ($m4bFileName && !$formatParser->getPlaceHolderValue(static::MAPPING_OPTIONS_PLACEHOLDERS[static::OPTION_TAG_SERIES])) {
                $fileNamePart .= "/" . $m4bFileName;
            }

            $batchOutputFile = $outputFile . "/" . $fileNamePart . ".m4b";

            $clonedInput->setArgument(static::ARGUMENT_INPUT, $baseDir);
            $clonedInput->setOption(static::OPTION_OUTPUT_FILE, $batchOutputFile);
            $clonedInput->setOption(static::OPTION_BATCH_PATTERN, []);

            $this->notice(sprintf("merge %s", $baseDir));
            $this->notice(sprintf("  =>  %s", $batchOutputFile));
            foreach (static::MAPPING_OPTIONS_PLACEHOLDERS as $optionName => $placeHolderName) {
                $placeHolderValue = $formatParser->getPlaceHolderValue($placeHolderName);
                if ($placeHolderValue !== "") {
                    $this->notice(sprintf("- %s: %s", $optionName, $placeHolderValue));
                    $this->setOptionIfUndefined($optionName, $placeHolderValue, $clonedInput);
                }
            }
            $this->notice("");
            $this->notice("================================");

            if ($clonedInput->getOption(static::OPTION_DRY_RUN)) {
                continue;
            }

            $batchJobs[] = $clonedInput;
        }
        $this->notice("");
        $this->notice("================================");

        return $batchJobs;
    }

    private function parseIncludeExtensions($extraExtensions = [])
    {
        return array_filter(
            array_merge(
                explode(',', $this->input->getOption(static::OPTION_INCLUDE_EXTENSIONS)),
                $extraExtensions
            )
        );
    }

    protected function normalizeDirectory($directory)
    {
        return rtrim(strtr($directory, [
            "\\" => "/",
        ]), "/");
    }

    /**
     * @return PlaceHolder[]
     */
    private static function createPlaceHoldersForOptions()
    {
        $placeHolders = [];
        foreach (static::MAPPING_OPTIONS_PLACEHOLDERS as $optionName => $placeHolder) {
            $placeHolders[] = new PlaceHolder($placeHolder);
        }
        return $placeHolders;
    }

    /**
     * @param MergeCommand $command
     * @param OutputInterface $output
     * @param InputInterface[] $batchJobs
     * @throws InvalidArgumentException
     */
    private function processBatchJobs(MergeCommand $command, OutputInterface $output, array $batchJobs)
    {
        gc_enable();
        foreach ($batchJobs as $clonedInput) {

            $baseDir = $clonedInput->getArgument(static::ARGUMENT_INPUT);

            try {
                $this->notice(sprintf("processing %s", $baseDir));
                $clonedCommand = clone $command;
                $clonedOutput = clone $output;
                $clonedCommand->execute($clonedInput, $clonedOutput);
                unset($clonedCommand);
                unset($clonedInput);
                unset($clonedOutput);
                gc_collect_cycles();
            } catch (Exception $e) {
                $this->error(sprintf("processing failed for %s: %s", $baseDir, $e->getMessage()));
                $this->debug(sprintf("error on %s: %s", $baseDir, $e->getTraceAsString()));
            }
        }
        gc_disable();
    }

    /**
     * @param $input
     * @throws Exception
     */
    private function ensureValidInputForSingleFileMode(InputInterface $input)
    {
        $outputFile = new SplFileInfo($input->getOption(static::OPTION_OUTPUT_FILE));
        if ($outputFile->isDir()) {
            throw new Exception(sprintf("Without --%s it is assumed that --%s is a file and NOT an existing directory", static::OPTION_BATCH_PATTERN, static::OPTION_OUTPUT_FILE));
        }
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @throws InvalidArgumentException
     * @throws Exception
     */
    private function processFiles(InputInterface $input, OutputInterface $output)
    {
        $this->initExecution($input, $output);
        $this->loadOutputFile();

        if (!$this->optForce && $this->isBatchMode($this->input) && $this->outputFile->isFile()) {
            $this->notice(sprintf("Output file %s already exists - skipping while in batch mode", $this->outputFile));
            return;
        }

        $this->loadInputFiles();
        $this->ensureOutputFileIsNotEmpty($this->outputFile);
        $this->processInputFiles();
    }

    private function loadOutputFile()
    {
        $this->outputFile = new SplFileInfo($this->input->getOption(static::OPTION_OUTPUT_FILE));
        $ext = $this->outputFile->getExtension();
        if (isset(static::AUDIO_EXTENSION_FORMAT_MAPPING[$ext]) && $this->input->getOption(static::OPTION_AUDIO_FORMAT) === static::AUDIO_EXTENSION_M4B) {
            $this->optAudioExtension = $ext;
            $this->optAudioFormat = static::AUDIO_EXTENSION_FORMAT_MAPPING[$ext];
            $this->optAudioCodec = static::AUDIO_FORMAT_CODEC_MAPPING[$this->optAudioFormat];
        }
    }

    /**
     * @throws Exception
     */
    private function loadInputFiles()
    {

        if ($this->outputFile->isFile() && !$this->optForce) {
            throw new Exception("Output file  " . $this->outputFile . " already exists - use --force to overwrite");
        }

        $this->debug("== load input files ==");
        $inputFiles = $this->input->getArgument(static::ARGUMENT_MORE_INPUT_FILES);
        $includeExtensions = $this->parseIncludeExtensions();

        $loader = new FileLoader();
        $loader->setIncludeExtensions($includeExtensions);
        $loader->add($this->argInputFile);
        foreach ($inputFiles as $fileLink) {
            $loader->add(new SplFileInfo($fileLink));
        }

        $this->filesToConvert = $loader->getFiles();
        foreach ($loader->getSkippedFiles() as $fileName => $skipReason) {
            $this->notice(sprintf("skipping %s (%s)", $fileName, $skipReason));
        }
    }

    /**
     * @throws Exception
     * @throws InvalidArgumentException
     */
    private function processInputFiles()
    {

        if (count($this->filesToConvert) === 0) {
            $this->warn("no files to convert for given input...");
            return;
        }

        $this->loadInputMetadataFromFirstFile();
        $this->lookupAndAddCover();
        $this->lookupAndAddDescription();

        if ($this->input->getOption(static::OPTION_NO_CONVERSION)) {
            $this->prepareMergeWithoutConversion();
        } else {
            $this->convertInputFiles();
        }


        // put tagloaders here?!
        $this->lookupAndAddCover();

        $chaptersFileContent = $this->lookupFileContents($this->argInputFile, "chapters.txt");
        if ($chaptersFileContent !== null) {
            $this->notice("importing chapters from existing chapters.txt");
            $chapterParser = new Mp4ChapsChapterParser();
            $chapters = $chapterParser->parse($chaptersFileContent);
        } else {
            $this->notice("rebuilding chapters from converted files title tags");
            $chapters = $this->chapterHandler->buildChaptersFromFiles($this->filesToMerge, $this->filesToConvert);
            $chapters = $this->replaceChaptersWithMusicBrainz($chapters);
        }
        $outputTempFile = $this->mergeFiles();

        $chapters = $this->adjustTooLongChapters($outputTempFile, $chapters);
        $this->tagMergedFile($outputTempFile, $chapters);

        $this->moveFinishedOutputFile($outputTempFile, $this->outputFile);

        $this->deleteTemporaryFiles();

        $this->notice(sprintf("successfully merged %d files to %s", count($this->filesToMerge), $this->outputFile));
    }

    protected function loadInputMetadataFromFirstFile()
    {
        reset($this->filesToConvert);

        $file = current($this->filesToConvert);
        if (!$file) {
            return;
        }

        /** @var FfmetaDataParser $metaData */
        $metaData = $this->readFileMetaData($file);
        $this->setMissingCommandLineOptionsFromTag($metaData->toTag());
    }



    private function lookupAndAddDescription()
    {
        $descriptionFileContents = $this->lookupFileContents($this->argInputFile, "description.txt");
        if ($descriptionFileContents !== null) {
            $this->setOptionIfUndefined(static::OPTION_TAG_DESCRIPTION, $descriptionFileContents);
        }
    }


    /**
     * @throws Exception
     * @throws InvalidArgumentException
     */
    private function prepareMergeWithoutConversion()
    {
        $coverTargetFile = new SPLFileInfo($this->argInputFile . "/cover.jpg");

        $this->filesToMerge = $this->filesToConvert;
        $extensions = [];
        $forceExtractCover = $this->optForce;
        foreach ($this->filesToMerge as $file) {
            $this->extractCover($file, $coverTargetFile, $forceExtractCover);
            $forceExtractCover = false;

            if (!in_array($file->getExtension(), $extensions, true)) {
                $extensions[] = $file->getExtension();
            }
        }

        if (count($extensions) === 0) {
            throw new Exception("no files found to merge");
        }
        if (count($extensions) > 1 && !$this->optForce) {
            throw new Exception("--no-conversion flag is unlikely to work, because files with multiple extensions are present, use --force to merge anyway");
        }

        $mergeExtension = current($extensions);

        if (isset(static::AUDIO_EXTENSION_FORMAT_MAPPING[$mergeExtension])) {
            $this->optAudioFormat = static::AUDIO_EXTENSION_FORMAT_MAPPING[$mergeExtension];
        }
    }

    /**
     * @throws InvalidArgumentException
     * @throws Exception
     */
    private function convertInputFiles()
    {
        $padLen = strlen(count($this->filesToConvert));
        $this->adjustBitrateForIpod($this->filesToConvert);

        $coverTargetFile = new SPLFileInfo($this->argInputFile . "/cover.jpg");


        $firstFile = reset($this->filesToConvert);
        if ($firstFile) {
            $this->extractCover($firstFile, $coverTargetFile, $this->optForce);
        }

        $outputTempDir = $this->createOutputTempDir();

        $ffmpeg = new Ffmpeg();
        $fdkaac = new Fdkaac();

        $jobs = $this->input->getOption(static::OPTION_JOBS) ? (int)$this->input->getOption(static::OPTION_JOBS) : 1;
        $taskPool = new Pool($jobs);

        foreach ($this->filesToConvert as $index => $file) {
//            $estimatedDuration = $this->metaHandler->estimateDuration($file);
//            $taskWeight = $estimatedDuration ? $estimatedDuration->milliseconds() / 1000 : 1;
            $pad = str_pad($index + 1, $padLen, "0", STR_PAD_LEFT);
            $outputFile = new SplFileInfo($outputTempDir . $pad . '-' . $file->getBasename("." . $file->getExtension()) . "-converting." . $this->optAudioExtension);
            $finishedOutputFile = new SplFileInfo($outputTempDir . $pad . '-' . $file->getBasename("." . $file->getExtension()) . "-finished." . $this->optAudioExtension);

            $this->filesToMerge[] = $finishedOutputFile;

            if ($outputFile->isFile()) {
                unlink($outputFile);
            }

            $options = new FileConverterOptions();
            $options->source = $file;
            $options->destination = $outputFile;
            $options->tempDir = $outputTempDir;
            $options->extension = $this->optAudioExtension;
            $options->codec = $this->optAudioCodec;
            $options->format = $this->optAudioFormat;
            $options->channels = $this->optAudioChannels;
            $options->sampleRate = $this->optAudioSampleRate;
            $options->bitRate = $this->optAudioBitRate;
            $options->force = $this->optForce;
            $options->profile = $this->input->getOption(static::OPTION_AUDIO_PROFILE);

            $taskPool->submit(new ConversionTask($ffmpeg, $fdkaac, $options)/*, $taskWeight*/);
        }


        $this->notice(sprintf("preparing conversion with %d simultaneous %s, please wait...", $jobs, $jobs === 1 ? "job" : "jobs"));


        $taskPool->process(function (Pool $taskPool) {
            static $counter = 0;
            static $spinnerPosition = 0;
            static $maxMessageLength = 0;

            $queueLength = count($taskPool->getProcessingQueue());
            if ($counter++ % 4 !== 0 && $queueLength > 0) {
                return;
            }

            $taskCount = count($taskPool->getTasks());
            $runningTaskCount = count($taskPool->getRunningTasks());
            $remainingTaskCount = $queueLength + $runningTaskCount;

            if ($taskPool === 0) {
                $message = sprintf("\rfinished %4d tasks, preparing next step", $taskCount);
            } else if ($runningTaskCount === 0) {
                $message = sprintf("\r%4d remaining / %4d total, preparing next task", $remainingTaskCount, $taskCount);
            } else if ($runningTaskCount > 0) {
                $message = sprintf("\r%4d remaining / %4d total", $remainingTaskCount, $taskCount);
            } else {
                $message = sprintf("\rpreparing conversion");
            }

            $chars = ['|', '/', '-', '\\'];
            $charCount = count($chars);
            $spinner = $chars[$spinnerPosition++ % $charCount];
            $message .= " " . $spinner;

            $maxMessageLength = max(mb_strlen($message), $maxMessageLength);
            $message = str_pad($message, $maxMessageLength);

            $this->output->write($message, false, OutputInterface::VERBOSITY_VERBOSE);
        });
        $this->output->writeln("", OutputInterface::VERBOSITY_VERBOSE);


        /** @var ConversionTask $task */
        foreach ($taskPool->getTasks() as $index => $task) {
            $file = $task->getOptions()->source;
            $outputFile = $task->getOptions()->destination;

            if (!$outputFile->isFile()) {
                throw new Exception("could not convert " . $file . " to " . $outputFile);
            }

            if ($outputFile->getSize() == 0) {
                unlink($outputFile);
                throw new Exception("could not convert " . $file . " to " . $outputFile);
            }
        }
    }

    /**
     * @return string
     * @throws Exception
     */
    private function createOutputTempDir()
    {
        $dir = $this->outputFile->getPath() ? $this->outputFile->getPath() . DIRECTORY_SEPARATOR : "";
        $dir .= $this->outputFile->getBasename("." . $this->outputFile->getExtension()) . "-tmpfiles" . DIRECTORY_SEPARATOR;

        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            $message = sprintf("Could not create temp directory %s", $dir);
            $this->debug($message);
            throw new Exception($message);
        }
        return $dir;
    }


    /**
     * @param Chapter[] $chapters
     * @return array|Chapter[]
     * @throws Exception
     */
    private function replaceChaptersWithMusicBrainz(array $chapters)
    {
        $mbId = $this->input->getOption(static::OPTION_MUSICBRAINZ_ID);
        if (!$mbId) {
            return $chapters;
        }

        $mbChapterParser = new MusicBrainzChapterParser($mbId);
        $mbChapterParser->setCache($this->cache);

        $mbXml = $mbChapterParser->loadRecordings();
        $mbChapters = $mbChapterParser->parseRecordings($mbXml);

        $chapterMarker = new ChapterMarker();
        $chapters = $chapterMarker->guessChaptersByTracks($mbChapters, $chapters);


        return $chapterMarker->normalizeChapters($chapters, static::NORMALIZE_CHAPTER_OPTIONS);

    }

    /**
     * @return SplFileInfo
     * @throws Exception
     */
    private function mergeFiles()
    {
        $outputTempFile = new SplFileInfo($this->createOutputTempDir() . "tmp_" . $this->outputFile->getBasename());
        $outputTempChaptersFile = $this->audioFileToChaptersFile($outputTempFile);

        if ($outputTempFile->isFile() && !unlink($outputTempFile)) {
            throw new Exception(sprintf("Could not delete temporary output file %s", $outputTempFile));
        }

        if ($outputTempChaptersFile->isFile() && !unlink($outputTempChaptersFile)) {
            throw new Exception(sprintf("Could not delete temporary chapters file %s", $outputTempChaptersFile));
        }

        if (count($this->filesToMerge) === 1) {
            $this->debug("only 1 file in merge list, copying file");
            copy(current($this->filesToMerge), $outputTempFile);
            return $outputTempFile;
        }

        // howto quote: http://ffmpeg.org/ffmpeg-utils.html#Quoting-and-escaping
        $listFile = $this->outputFile . ".listing.txt";
        file_put_contents($listFile, '');

        /**
         * @var SplFileInfo $file
         */
        foreach ($this->filesToMerge as $index => $file) {
            $quotedFilename = "'" . implode("'\''", explode("'", $file->getRealPath())) . "'";
            file_put_contents($listFile, "file " . $quotedFilename . PHP_EOL, FILE_APPEND);
            // file_put_contents($listFile, "duration " . ($numberedChapters[$index]->getLength()->milliseconds() / 1000) . PHP_EOL, FILE_APPEND);
        }

        $command = [
            "-f", "concat",
            "-safe", "0",
            "-vn",
            "-i", $listFile,
            "-max_muxing_queue_size", "9999",
            "-c", "copy",
        ];


        // alac can be used for m4a/m4b, but not ffmpeg says it is not mp4 compilant
        if ($this->optAudioFormat && $this->optAudioCodec !== static::AUDIO_CODEC_ALAC) {
            $command[] = "-f";
            $command[] = $this->optAudioFormat;
        }

        $command[] = $outputTempFile;


        $this->ffmpeg($command, "merging " . $outputTempFile . ", this can take a while");

        if (!$outputTempFile->isFile()) {
            throw new Exception("could not merge to " . $outputTempFile);
        }

        if (!$this->optDebug) {
            unlink($listFile);
        }
        return $outputTempFile;
    }

    /**
     * @param SplFileInfo $outputFile
     * @param Chapter[] $chapters
     * @return array|Chapter[]
     * @throws InvalidArgumentException
     */
    private function adjustTooLongChapters(SplFileInfo $outputFile, array $chapters)
    {
        // value examples:
        // 300 => maxLength = 300 seconds
        // 300,900 => desiredLength = 300 seconds, maxLength = 900 seconds
        $maxChapterLengthOriginalValue = $this->input->getOption(static::OPTION_MAX_CHAPTER_LENGTH);
        $maxChapterLengthParts = explode(",", $maxChapterLengthOriginalValue);

        $desiredChapterLengthSeconds = $maxChapterLengthParts[0] ?? 0;
        $maxChapterLengthSeconds = $maxChapterLengthParts[1] ?? $desiredChapterLengthSeconds;

        $maxChapterLength = new TimeUnit((int)$maxChapterLengthSeconds, TimeUnit::SECOND);
        $desiredChapterLength = new TimeUnit((int)$desiredChapterLengthSeconds, TimeUnit::SECOND);

        // at least one option has to be defined to adjust too long chapters
        if ($maxChapterLength->milliseconds() === 0) {
            return $chapters;
        }

        if ($maxChapterLength->milliseconds() > 0) {
            $this->chapterHandler->setMaxLength($maxChapterLength);
            $this->chapterHandler->setDesiredLength($desiredChapterLength);
        }

        $silenceDetectionOutput = $this->detectSilencesForChapterGuessing($outputFile);
        $silenceParser = new SilenceParser();
        $silences = $silenceParser->parse($silenceDetectionOutput);
        return $this->chapterHandler->adjustChapters($chapters, $silences);
    }

    /**
     * @param SplFileInfo $outputTmpFile
     * @param Chapter[] $chapters
     * @throws Exception
     */
    private function tagMergedFile(SplFileInfo $outputTmpFile, array $chapters)
    {
        $tag = new Tag();
        $tag->chapters = $chapters;

        $tagLoaderComposite = new TagTransferComposite($tag);
        if ($openPackagingFormatContent = $this->lookupFileContents($this->argInputFile, "metadata.opf")) {
            $this->notice("enhancing tag with additional metadata from metadata.opf");
            $tagLoaderComposite->add(new OpenPackagingFormat($openPackagingFormatContent));
        }

        if ($ffmetadataContent = $this->lookupFileContents($this->argInputFile, "ffmetadata.txt")) {
            $this->notice("enhancing tag with additional metadata from ffmetadata.txt");
            $parser = new FfmetaDataParser();
            $parser->parse($ffmetadataContent);
            $tagLoaderComposite->add(new Ffmetadata($parser));
        }

        $flags = new ConditionalFlags();
        $flags->insertIf(InputOptions::FLAG_ADJUST_FOR_IPOD, $this->input->getOption(static::OPTION_ADJUST_FOR_IPOD));

        $tagLoaderComposite->add(new InputOptions($this->input, $flags));

        $tag = $tagLoaderComposite->load();
        $this->tagFile($outputTmpFile, $tag);
        $this->notice(sprintf("tagged file %s (artist: %s, name: %s, chapters: %d)", $outputTmpFile->getBasename(), $tag->artist, $tag->title, count($tag->chapters)));
    }

    /**
     * @param SplFileInfo $outputTempFile
     * @param SplFileInfo $outputFile
     * @throws Exception
     */
    private function moveFinishedOutputFile(SplFileInfo $outputTempFile, SplFileInfo $outputFile)
    {
        $outputDir = dirname($outputFile);
        if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true)) {
            throw new Exception(sprintf("Could not create path for file %s", $outputFile));
        }

        if (!rename($outputTempFile, $outputFile)) {
            throw new Exception(sprintf("Could not rename output file from %s to %s", $outputTempFile, $outputFile));
        }

        $sourceChaptersFile = $this->audioFileToChaptersFile($outputTempFile);
        $destinationChaptersFile = $this->audioFileToChaptersFile($outputFile);
        if ($sourceChaptersFile->isFile() && !rename($sourceChaptersFile, $destinationChaptersFile)) {
            throw new Exception(sprintf("Could not rename chapters file from %s to %s", $sourceChaptersFile, $destinationChaptersFile));
        }

        $this->notice(sprintf("moved temporary %s to %s", $outputTempFile->getBasename(), $outputFile));
    }

    private function deleteTemporaryFiles()
    {
        if ($this->optDebug) {
            return;
        }

        if ($this->input->getOption(static::OPTION_NO_CONVERSION)) {
            return;
        }

        try {
            $this->deleteFilesAndParentDir($this->filesToMerge);
        } catch (Throwable $e) {
            $this->error("could not delete temporary files: ", $e->getMessage());
            $this->debug("trace:", $e->getTraceAsString());
        }

    }

    private function deleteFilesAndParentDir(array $files)
    {
        $file = null;
        foreach ($files as $file) {
            unlink($file);
        }
        if ($file === null) {
            return true;
        }
        $parentDir = dirname($file);
        $recIt = new RecursiveDirectoryIterator($parentDir, FilesystemIterator::SKIP_DOTS);
        $it = new IteratorIterator($recIt);
        $filesToDelete = iterator_to_array($it);
        if (count($filesToDelete) > 0) {
            return false;
        }
        rmdir($parentDir);
        return true;
    }


}
