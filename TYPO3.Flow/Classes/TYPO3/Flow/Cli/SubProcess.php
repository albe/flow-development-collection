<?php
namespace TYPO3\Flow\Cli;

use TYPO3\Flow\Core\ApplicationContext;

/**
 * A wrapper for a flow sub process that allows for sending arbitrary commands to the same request
 *
 * Usage:
 *  $subProcess = new SubProcess($applicationContext);
 *  $subProcessResponse = $subProcess->execute('some:flow:command');
 */
class SubProcess
{
    /**
     * @var resource|boolean
     */
    protected $subProcess = false;

    /**
     * @var array
     */
    protected $pipes = [];

    /**
     * @var ApplicationContext
     */
    protected $context;

    /**
     * @param ApplicationContext $context
     */
    public function __construct(ApplicationContext $context)
    {
        $this->context = $context;

        $this->execute('');
        // Flush response pipe
        $this->getSubProcessResponse();
    }

    /**
     * @param string $commandLine
     * @return string
     * @throws \Exception
     */
    public function execute($commandLine)
    {
        if (is_resource($this->subProcess)) {
            $subProcessStatus = proc_get_status($this->subProcess);
            if ($subProcessStatus['running'] === false) {
                proc_close($this->subProcess);
            }
        };
        if (!is_resource($this->subProcess)) {
            list($this->subProcess, $this->pipes) = $this->launchSubProcess();
            if ($this->subProcess === false || !is_array($this->pipes)) {
                throw new \Exception('Failed launching the shell sub process');
            }
        }
        fwrite($this->pipes[0], $commandLine . "\n");
        fflush($this->pipes[0]);

        return $this->getSubProcessResponse();
    }

    /**
     * Cleanly terminates the given sub process
     *
     * @return void
     */
    public function quit()
    {
        fwrite($this->pipes[0], "QUIT\n");
        fclose($this->pipes[0]);
        fclose($this->pipes[1]);
        fclose($this->pipes[2]);
        proc_close($this->subProcess);
    }

    /**
     * Launch sub process
     *
     * @return array|boolean The new sub process and its STDIN, STDOUT, STDERR pipes – or FALSE if an error occurred.
     * @throws \RuntimeException
     */
    protected function launchSubProcess()
    {
        $phpBinary = $this->detectPhpBinary();
        $systemCommand = 'FLOW_ROOTPATH=' . escapeshellarg(FLOW_PATH_ROOT) . ' FLOW_PATH_TEMPORARY_BASE=' . escapeshellarg(FLOW_PATH_TEMPORARY_BASE) . ' FLOW_CONTEXT=' . (string)$this->context . ' ' . $phpBinary . ' -c ' . escapeshellarg(php_ini_loaded_file()) . ' ' . escapeshellarg(FLOW_PATH_FLOW . 'Scripts/flow.php') . ' --start-slave';
        $descriptorSpecification = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'a']];
        $this->subProcess = proc_open($systemCommand, $descriptorSpecification, $this->pipes);
        if (!is_resource($this->subProcess)) {
            throw new \RuntimeException('Could not execute sub process.');
        }

        $read = [$this->pipes[1]];
        $write = null;
        $except = null;
        $readTimeout = 30;

        stream_select($read, $write, $except, $readTimeout);

        $subProcessStatus = proc_get_status($this->subProcess);
        return ($subProcessStatus['running'] === true) ? [$this->subProcess, $this->pipes] : false;
    }

    /**
     * Try to detect the PHP binary command that matches the currently executed PHP Version
     * @return string
     */
    protected function detectPhpBinary()
    {
        $possiblePhpBinaries = [];
        $possiblePhpBinaries[] = 'php';	// easy attempt, let OS find the correct path
        if (in_array(PHP_SAPI, array('cli', 'cli-server', 'phpdbg'))) {
            $possiblePhpBinaries[] = PHP_BINARY;    // PHP_BINARY is mostly correct in CLI SAPI mode
        }
        $possiblePhpBinaries[] = PHP_BINDIR . '/php';   // Try PHP_BINDIR, which might be correct if PHP was compiled on this machine and not moved
        if ($phpPath = getenv('PHP_PATH')) {
            $possiblePhpBinaries[] = $phpPath . '/php';
        }
        if ($phpPear = getenv('PHP_PEAR_PHP_BIN')) {
            $possiblePhpBinaries[] = $phpPear;
        }

        foreach ($possiblePhpBinaries as $phpBinary) {
            if (DIRECTORY_SEPARATOR === '/') {
                $phpBinary = '"' . escapeshellcmd($phpBinary) . '"';
            } else {
                $phpBinary = escapeshellarg($phpBinary);
            }

            exec($phpBinary . ' -v', $phpVersionString);
            if (!isset($phpVersionString[0]) || strpos($phpVersionString[0], 'PHP') !== 0) {
                // not a PHP executable
                continue;
            }
            $versionStringParts = explode(' ', $phpVersionString[0]);
            $phpVersion = isset($versionStringParts[1]) ? trim($versionStringParts[1]) : null;
            if ($phpVersion === PHP_VERSION) {
                return $phpBinary;
            }
        }
        throw new \RuntimeException('Could not find the PHP binary matching the current environment. Attempted ' . implode(',', array_map(function($v) { return '"' . $v. '"'; }, $possiblePhpBinaries)));
    }

    /**
     * Returns the currently pending response from the sub process
     *
     * @return string
     */
    protected function getSubProcessResponse()
    {
        if (!is_resource($this->subProcess)) {
            return '';
        }
        $responseLines = [];
        while (feof($this->pipes[1]) === false) {
            $responseLine = fgets($this->pipes[1]);
            if ($responseLine === false) {
                break;
            }
            $trimmedResponseLine = trim($responseLine);
            if ($trimmedResponseLine === 'READY') {
                break;
            }
            if ($trimmedResponseLine === '') {
                continue;
            }
            $responseLines[] = $trimmedResponseLine;
        }
        return implode("\n", $responseLines);
    }
}
