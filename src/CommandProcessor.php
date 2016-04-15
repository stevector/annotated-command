<?php
namespace Consolidation\AnnotatedCommand;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;

use Consolidation\OutputFormatters\FormatterManager;

/**
 * Process a command, including hooks and other callbacks
 */
class CommandProcessor
{
    protected $hookManager;
    protected $formatterManager;

    public function __construct($hookManager)
    {
        $this->hookManager = $hookManager;
    }

    public function hookManager()
    {
        return $this->hookManager;
    }

    public function setFormatterManager(FormatterManager $formatterManager)
    {
        $this->formatterManager = $formatterManager;
    }

    public function formatterManager()
    {
        return $this->formatterManager;
    }

    public function getFormatter($format, $annotationData)
    {
        if (!isset($this->formatterManager)) {
            return;
        }
        return $this->formatterManager->getFormatter($format, $annotationData);
    }

    public function process(
        OutputInterface $output,
        $names,
        $commandCallback,
        $annotationData,
        $args
    ) {
        $result = [];
        try {
            $result = $this->validateRunAndAlter(
                $names,
                $commandCallback,
                $args
            );
        } catch (\Exception $e) {
            $result = new CommandError($e->getCode(), $e->getMessage());
        }
        // Recover options from the end of the args
        $options = end($args);
        return $this->handleResults($output, $names, $result, $annotationData, $options);
    }

    public function validateRunAndAlter(
        $names,
        $commandCallback,
        $args
    ) {
        // Validators return any object to signal a validation error;
        // if the return an array, it replaces the arguments.
        $validated = $this->hookManager()->validateArguments($names, $args);
        if (is_object($validated)) {
            return $validated;
        }
        if (is_array($validated)) {
            $args = $validated;
        }

        // Run the command, alter the results, and then handle output and status
        $result = $this->runCommandCallback($commandCallback, $args);
        return $this->processResults($names, $result, $args);
    }

    public function processResults($names, $result, $args = [])
    {
        return $this->hookManager()->alterResult($names, $result, $args);
    }

    /**
     * Handle the result output and status code calculation.
     */
    public function handleResults(OutputInterface $output, $names, $result, $annotationData, $options = [])
    {
        $status = $this->hookManager()->determineStatusCode($names, $result);
        if (is_integer($result) && !isset($status)) {
            $status = $result;
            $result = null;
        }
        $status = $this->interpretStatusCode($status);

        // Get the structured output, the output stream and the formatter
        $outputText = $this->hookManager()->extractOutput($names, $result);
        $output = $this->chooseOutputStream($output, $status);
        $formatter = $this->chooseFormatter($annotationData, $options, $status);

        // Output the result text and return status code.
        $this->writeCommandOutput($outputText, $formatter, $options, $output);
        return $status;
    }

    /**
     * Run the main command callback
     */
    protected function runCommandCallback($commandCallback, $args)
    {
        $result = false;
        try {
            $result = call_user_func_array($commandCallback, $args);
        } catch (\Exception $e) {
            $result = new CommandError($e->getMessage(), $e->getCode());
        }
        return $result;
    }

    /**
     * Select the formatter to use.
     *
     * Note that if there is an error (status code is nonzero),
     * then the result object is going to be an error object. This
     * object may have a string that may be extracted and printed,
     * but it should never be formatted per the --format option.
     */
    protected function chooseFormatter($annotationData, $options, $status)
    {
        if ($status) {
            return;
        }
        $format = $this->getFormat($options);
        return $this->getFormatter($format, $annotationData);
    }

    /**
     * Determine the formatter that should be used to render
     * output.
     *
     * If the user specified a format via the --format option,
     * then always return that.  Otherwise, return the default
     * format, unless --pipe was specified, in which case
     * return the default pipe format, format-pipe.
     *
     * n.b. --pipe is a handy option introduced in Drush 2
     * (or perhaps even Drush 1) that indicates that the command
     * should select the output format that is most appropriate
     * for use in scripts (e.g. to pipe to another command).
     */
    protected function getFormat($options)
    {
        $options += [
            'default-format' => false,
            'pipe' => false,
        ];
        $options += [
            'format' => $options['default-format'],
            'format-pipe' => $options['default-format'],
        ];

        $format = $options['format'];
        if ($options['pipe']) {
            $format = $options['format-pipe'];
        }
        return $format;
    }

    /**
     * Determine whether we should use stdout or stderr.
     */
    protected function chooseOutputStream(OutputInterface $output, $status)
    {
        // If the status code indicates an error, then print the
        // result to stderr rather than stdout
        if ($status && ($output instanceof ConsoleOutputInterface)) {
            return $output->getErrorOutput();
        }
        return $output;
    }

    /**
     * If the result object is a string, then print it.
     */
    protected function writeCommandOutput(
        $outputText,
        $formatter,
        $options,
        OutputInterface $output
    ) {
        // If there is a formatter, use it.
        if (isset($outputText) && $formatter) {
            $formatter->write($outputText, $options, $output);
            return;
        }
        // If there is no formatter, we will print strings,
        // but can do no more than that.
        if (is_string($outputText)) {
            $output->writeln($outputText);
        }
    }

    /**
     * If a status code was set, then return it; otherwise,
     * presume success.
     */
    protected function interpretStatusCode($status)
    {
        if (isset($status)) {
            return $status;
        }
        return 0;
    }
}
