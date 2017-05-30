<?php

namespace Arc\Installer\Console;

use GuzzleHttp\Client;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Process\Process;
use ZipArchive;

class NewCommand extends Command
{
    /**
     * The boilerplate directory of the plugin.
     *
     * @var string
     **/
    const DEFAULT_PLUGIN_DIRECTORY = '~/Code/plugins/vendor/plugin-name';

    /**
     * The boilerplate name of the plugin file.
     *
     * @var string
     **/
    const DEFAULT_PLUGIN_FILENAME = 'plugin-name.php';

    /**
     * The boilerplate name of the plugin.
     *
     * @var string
     **/
    const DEFAULT_PLUGIN_NAME = 'My Plugin Name';

    /**
     * The boilerplate namespace of the plugin.
     *
     * @var string
     **/
    const DEFAULT_PLUGIN_NAMESPACE = 'Vendor\PluginName';

    /**
     * The boilerplate slug/composer name of the plugin.
     *
     * @var string
     */
    const DEFAULT_PLUGIN_SLUG = 'plugin-name';

    /**
     * The boilerplate description in the plugin entry file.
     *
     * @var string
     */
    const DEFAULT_PLUGIN_DESCRIPTION = 'Description: A description of this plugin';

    /**
     * The boilerplate composer description.
     *
     * @var string
     **/
    const DEFAULT_COMPOSER_DESCRIPTION = '"description": "A description of this plugin",';

    /**
     * The boilerplate plugin URI.
     *
     * @var string
     **/
    const DEFAULT_PLUGIN_URI = 'Plugin URI: http://plugin.com.au';

    /**
     * The boilerplate Author name in the plugin file.
     *
     * @var string
     **/
    const DEFAULT_PLUGIN_AUTHOR = 'Author: My Name';

    /**
     * The boilerplate composer author.
     *
     * @var string
     **/
    const DEFAULT_COMPOSER_AUTHOR = '"name": "My Name",';

    /**
     * The boilerplate plugin author URI.
     *
     * @var string
     **/
    const DEFAULT_PLUGIN_AUTHOR_URI = 'Author URI: http://myname.com.au';

    protected $archiveName;
    protected $pluginName;
    protected $pluginNamespace;
    protected $pluginSlug;

    /**
     * Configure the command options.
     *
     * @return void
     */
    protected function configure()
    {
        $this->setName('new')
            ->setDescription('Create a new Arc framework WordPress Plugin.')
            ->addOption('dev', null, InputOption::VALUE_NONE, 'Installs the latest "development" release');
    }

    /**
     * Execute the command.
     *
     * @param \Symfony\Component\Console\Input\InputInterface   $input  the command input stream
     * @param \Symfony\Component\Console\Output\OutputInterface $output the output stream for any feedback
     *
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('The Zip PHP extension is not installed. Please install it and try again.');
        }

        $this->output = $output;

        // Get question helper
        $questionHelper = $this->getHelper('question');

        // Get the plugin name
        $question = new Question('Enter plugin name (e.g. <comment>Moblish Thundankle Perfurbulator</comment>): ');
        while (is_null($this->pluginName)) {
            $this->pluginName = $questionHelper->ask($input, $this->output, $question);
        }

        // Get the plugin slug
        $defaultSlug = $this->slugify($this->pluginName);
        $question = new Question('Enter plugin slug (default "<comment>'.$defaultSlug.'</comment>"): ', $defaultSlug);
        $question->setAutocompleterValues([$defaultSlug]);
        $this->pluginSlug = $questionHelper->ask($input, $this->output, $question);

        $this->verifyApplicationDoesntExist();

        // Get the plugin namespace
        $defaultNamespace = str_replace(' ', '\\', ucwords($this->pluginName));
        $question = new Question('Enter plugin namespace (default "<comment>'.$defaultNamespace.'</comment>"): ', $defaultNamespace);
        $question->setAutocompleterValues([$defaultNamespace]);
        $this->pluginNamespace = $questionHelper->ask($input, $this->output, $question);

        //get the plugin URI
        $question = new Question('Enter plugin URI (default <comment>""</comment>): ', '');
        $this->pluginUri = $questionHelper->ask($input, $this->output, $question);

        //get the plugin description
        $question = new Question('Enter plugin description (default <comment>""</comment>): ', '');
        $this->pluginDescription = $questionHelper->ask($input, $this->output, $question);

        //get the plugin author
        $question = new Question('Enter plugin author (default <comment>""</comment>): ', '');
        $this->pluginAuthor = $questionHelper->ask($input, $this->output, $question);

        //get the plugin author uri
        $question = new Question('Enter plugin author URI (default <comment>""</comment>): ', '');
        $this->pluginAuthorUri = $questionHelper->ask($input, $this->output, $question);

        $this->info('Building plugin...');

        $version = $this->getVersion($input);

        $this->download($zipFile = $this->makeFilename(), $version)
            ->extract($zipFile)
            ->rename($this->getArchiveDirectoryName(), $this->pluginSlug)
            ->cleanUp($zipFile);

        $composer = $this->findComposer();

        $commands = [
            $composer.' install --no-scripts --no-suggest',
        ];

        if ($input->getOption('no-ansi')) {
            $commands = array_map(
                function ($value) {
                    return $value.' --no-ansi';
                },
                $commands
            );
        }

        $process = new Process(implode(' && ', $commands), $this->pluginSlug, null, null, null);

        if ('\\' !== DIRECTORY_SEPARATOR && file_exists('/dev/tty') && is_readable('/dev/tty')) {
            $process->setTty(true);
        }

        $process->run(
            function ($type, $line) {
                $this->output->write($line);
            }
        );

        // Rename plugin.php to slug
        $this->pluginFilename = $this->pluginSlug.'.php';
        $this->pluginFile = $this->pluginSlug.'/'.$this->pluginFilename;
        $this->rename($this->pluginSlug.'/'.self::DEFAULT_PLUGIN_FILENAME, $this->pluginFile);

        // Change name of plugin in plugin file
        $this->replaceStringWithAnotherInFile(self::DEFAULT_PLUGIN_NAME, $this->pluginName, $this->pluginFile);

        // Change plugin URI in plugin file
        $this->replaceStringWithAnotherInFile(self::DEFAULT_PLUGIN_URI, 'Plugin URI: '.$this->pluginUri, $this->pluginFile);

        // Change plugin description in plugin file
        $this->replaceStringWithAnotherInFile(self::DEFAULT_PLUGIN_DESCRIPTION, 'Description: '.$this->pluginDescription, $this->pluginFile);

        // Change plugin author in plugin file
        $this->replaceStringWithAnotherInFile(self::DEFAULT_PLUGIN_AUTHOR, 'Author: '.$this->pluginAuthor, $this->pluginFile);

        // Change plugin author URI in plugin file
        $this->replaceStringWithAnotherInFile(self::DEFAULT_PLUGIN_AUTHOR_URI, 'Author URI: '.$this->pluginAuthorUri, $this->pluginFile);

        // Change plugin class namespace in plugin file
        $this->replaceStringWithAnotherInFile(self::DEFAULT_PLUGIN_NAMESPACE, $this->pluginNamespace, $this->pluginFile);

        // Change namespace in plugin class
        $this->pluginClass = $this->pluginSlug.'/app/Plugin.php';
        $this->replaceStringWithAnotherInFile(self::DEFAULT_PLUGIN_NAMESPACE, $this->pluginNamespace, $this->pluginClass);

        // Change vendor name in composer.json
        // TODO Collect vendor name from user

        // Change plugin name in composer.json
        $this->composerJson = $this->pluginSlug.'/composer.json';
        $this->replaceStringWithAnotherInFile(self::DEFAULT_PLUGIN_SLUG, $this->pluginSlug, $this->composerJson);

        // Change plugin description in composer.json
        $this->replaceStringWithAnotherInFile(self::DEFAULT_COMPOSER_DESCRIPTION, '"description": "'.$this->pluginDescription.'",', $this->composerJson);

        // Change author name in plugin description
        $this->replaceStringWithAnotherInFile(self::DEFAULT_COMPOSER_AUTHOR, '"name": "'.$this->pluginAuthor.'",', $this->composerJson);

        // Change author email in plugin description
        // TODO Collect author email from user

        // Change psr-4 namespace in composer.json
        $this->replaceStringWithAnotherInFile(
            addslashes(self::DEFAULT_PLUGIN_NAMESPACE),
            addslashes($this->pluginNamespace),
            $this->composerJson
        );

        // Change wordpress path in .env file
        // TODO collect wordpress path from user

        // Change plugin path in .env file
        $this->envFile = $this->pluginSlug.'/.env';
        $this->replaceStringWithAnotherInFile(self::DEFAULT_PLUGIN_DIRECTORY, realpath($this->pluginSlug), $this->envFile);

        // Change plugin filename in .env file
        $this->replaceStringWithAnotherInFile(self::DEFAULT_PLUGIN_FILENAME, $this->pluginFilename, $this->envFile);

        // Change plugin path in tests/bootstrap
        $this->replaceStringWithAnotherInFile(self::DEFAULT_PLUGIN_FILENAME, $this->pluginFilename, $this->pluginSlug.'/tests/bootstrap.php');

        // Change plugin namespace in test case constructor
        $this->replaceStringWithAnotherInFile(self::DEFAULT_PLUGIN_NAMESPACE, $this->pluginNamespace, $this->pluginSlug.'/tests/TestCase.php');

        // Change plugin namespace and filename for Arc CLI
        $this->replaceStringWithAnotherInFile(self::DEFAULT_PLUGIN_NAMESPACE, $this->pluginNamespace, $this->pluginSlug.'/arc');
        $this->replaceStringWithAnotherInFile(self::DEFAULT_PLUGIN_FILENAME, $this->pluginFilename, $this->pluginSlug.'/arc');

        // CD into the plugin directory and clear composer autoload cache
        shell_exec('cd '.$this->pluginSlug.'; '.$this->findComposer().' dump-autoload');

        $this->info('Plugin ready! Build something adequate!');
    }

    /**
     * Verify that the application does not already exist.
     *
     * @return void
     */
    protected function verifyApplicationDoesntExist()
    {
        if ((is_dir($this->pluginSlug) || is_file($this->pluginSlug)) && $this->pluginSlug != getcwd()) {
            throw new RuntimeException('Plugin already exists!');
        }
    }

    /**
     * Generate a random temporary filename.
     *
     * @return string
     */
    protected function makeFilename()
    {
        return getcwd().'/arc_'.md5(time().uniqid()).'.zip';
    }

    /**
     * Download the temporary Zip to the given file.
     *
     * @param string $zipFile the file to download the zip contents to
     * @param string $version the version to download, 'develop' or 'master'
     *
     * @return $this
     */
    protected function download($zipFile, $version = 'master')
    {
        switch ($version) {
            case 'develop':
                $this->archiveName = 'develop.zip';
                break;
            case 'master':
                $this->archiveName = 'master.zip';
                break;
        }

        $response = (new Client())->get('https://github.com/ArcFramework/plugin/archive/'.$this->archiveName);

        file_put_contents($zipFile, $response->getBody());

        return $this;
    }

    /**
     * Extract the Zip file into the given directory.
     *
     * @param string $zipFile   the zip archive to extract
     * @param string $directory the location to put the contents of the zip archive
     *
     * @return $this
     */
    protected function extract($zipFile, $directory = '.')
    {
        $archive = new ZipArchive();

        $archive->open($zipFile);

        $archive->extractTo($directory);

        $archive->close();

        return $this;
    }

    /**
     * Clean-up the Zip file.
     *
     * @param string $zipFile the zip file to remove/clean up
     *
     * @return $this
     */
    protected function cleanUp($zipFile)
    {
        @chmod($zipFile, 0777);

        @unlink($zipFile);

        return $this;
    }

    /**
     * Get the version that should be downloaded.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input the command input stream
     *
     * @return string
     */
    protected function getVersion(InputInterface $input)
    {
        if ($input->getOption('dev')) {
            return 'develop';
        }

        return 'master';
    }

    /**
     * Get the composer command for the environment.
     *
     * @return string
     */
    protected function findComposer()
    {
        if (file_exists(getcwd().'/composer.phar')) {
            return '"'.PHP_BINARY.'" composer.phar';
        }

        return 'composer';
    }

    /**
     * Get the name of the directory which the archive extracts to.
     *
     * @return string
     */
    protected function getArchiveDirectoryName()
    {
        return 'plugin-'.pathinfo($this->archiveName, PATHINFO_FILENAME);
    }

    /**
     * Rename the directory.
     *
     * @param string $originalName the name of the directory to rename
     * @param string $newName      the new name for the directory
     *
     * @return $this
     */
    protected function rename($originalName, $newName)
    {
        rename(getcwd().'/'.$originalName, $newName);

        return $this;
    }

    /**
     * Get the directory of the plugin files.
     *
     * @return string
     */
    protected function getDirectory()
    {
        return getcwd().'/'.$this->slug;
    }

    /**
     * Convert the given namespace string to a slug.
     *
     * @param string $title     the name to convert to a slug
     * @param string $separator the character to use to separate the slug words
     *
     * @return string
     **/
    protected function slugify($title, $separator = '-')
    {
        // Convert all dashes/underscores into separator
        $flip = $separator == '-' ? '_' : '-';

        $title = preg_replace('!['.preg_quote($flip).']+!u', $separator, $title);
        // Remove all characters that are not the separator, letters, numbers, or whitespace.

        $title = preg_replace('![^'.preg_quote($separator).'\pL\pN\s]+!u', '', mb_strtolower($title));
        // Replace all separator characters and whitespace by a single separator

        $title = preg_replace('!['.preg_quote($separator).'\s]+!u', $separator, $title);

        return trim($title, $separator);
    }

    /**
     * Output the given message to the console as a comment message.
     *
     * @param string $message the message to pass to output
     *
     * @return void
     **/
    protected function comment($message)
    {
        $this->output($message, 'comment');
    }

    /**
     * Output the given message to the console as an info message.
     *
     * @param string $message the message to pass to output
     *
     * @return void
     **/
    protected function info($message)
    {
        $this->output($message, 'info');
    }

    /**
     * Output the given message to the console with the given class.
     *
     * @param string $message the message to pass to output
     * @param string $class   the class of message to output
     *
     * @return void
     **/
    protected function output($message, $class = 'info')
    {
        $this->output->writeln("<$class>$message</$class>");
    }

    /**
     * Replaces all instances of the given string with another string in the file at the given path.
     *
     * @param string $string   String to be replaced
     * @param string $another  String to be replaced with
     * @param string $filepath The full path of the file to be replaced
     *
     * @return void
     **/
    protected function replaceStringWithAnotherInFile($string, $another, $filepath)
    {
        // Tell the user what's happening
        $this->output->writeln("Replacing <info>$string</info> with <info>$another</info> in <comment>$filepath</comment>");

        // Read the contents of the file
        $fileContents = file_get_contents($filepath);

        // Replace all instances of the string with another
        $newContents = str_replace($string, $another, $fileContents);

        // Write the new contents back to the original path
        file_put_contents($filepath, $newContents);
    }
}
