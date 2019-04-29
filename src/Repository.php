<?php

namespace Meeva\Monorepo;

use Composer\Composer;
use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Version\VersionGuesser;
use Composer\Package\Version\VersionParser;
use Composer\Repository\ArrayRepository;
use Composer\Util\ProcessExecutor;
use Symfony\Component\Finder\Finder;

class Repository extends ArrayRepository
{
    /**
     * @var ArrayLoader
     */
    private $loader;

    /**
     * @var VersionGuesser
     */
    private $versionGuesser;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var ProcessExecutor
     */
    private $process;

    /**
     * @var array
     */
    private $options;

    /**
     * @var Config
     */
    private $workdirConfig;

    protected $enabled = true;

    /**
     * Initializes path repository.
     *
     * @param IOInterface $io
     * @param Config      $config
     */
    public function __construct(IOInterface $io, Config $config, Composer $composer)
    {
        $this->loader         = new ArrayLoader(null, true);
        $this->process        = new ProcessExecutor($io);
        $this->versionGuesser = new VersionGuesser($config, $this->process, new VersionParser());
        $this->config         = $config;

        parent::__construct();
    }

    public function disable($disabled = true)
    {
        $this->enabled = !$disabled;

        return $this;
    }

    public function isEnabled()
    {
        $skipForce = getenv('COMPOSER_MONOREPO');
        if ('skip' === $skipForce) {
            return false;
        }

        if ('force' === $skipForce) {
            return true;
        }

        return $this->enabled;
    }

    /**
     * Initializes path repository.
     *
     * This method will basically read the folder and add the found package.
     */
    protected function initialize()
    {
        parent::initialize();

        if (!$this->isEnabled()) {
            return;
        }

        foreach ($this->getUrlMatches() as $url) {
            $path             = realpath($url) . DIRECTORY_SEPARATOR;
            $composerFilePath = $path . 'composer.json';

            if (!file_exists($composerFilePath)) {
                continue;
            }

            $json            = file_get_contents($composerFilePath);
            $package         = JsonFile::parseJson($json, $composerFilePath);
            $package['dist'] = array(
                'type'      => 'path',
                'url'       => $url,
                'reference' => sha1($json . serialize($this->options)),
            );
            $package['transport-options'] = $this->options;

            // carry over the root package version if this path repo is in the same git repository as root package
            if (!isset($package['version']) && ($rootVersion = getenv('COMPOSER_ROOT_VERSION'))) {
                if (
                    0 === $this->process->execute('git rev-parse HEAD', $ref1, $path)
                    && 0 === $this->process->execute('git rev-parse HEAD', $ref2)
                    && $ref1 === $ref2
                ) {
                    $package['version'] = $rootVersion;
                }
            }

            if (!isset($package['version'])) {
                $versionData        = $this->versionGuesser->guessVersion($package, $path);
                $package['version'] = $versionData['version'] ?: 'dev-master';
            }

            $output = '';
            if (is_dir($path . DIRECTORY_SEPARATOR . '.git') && 0 === $this->process->execute('git log -n1 --pretty=%H', $output, $path)) {
                $package['dist']['reference'] = trim($output);
            }
            $package = $this->loader->load($package);
            $this->addPackage($package);
        }
    }

    public function getCurrentProjectDir()
    {
        // TODO: Aus Composer Process bekommen?
        return getcwd();
    }

    /**
     * Get a list of all (possibly relative) path names matching given url (supports globbing).
     *
     * @return \Generator|string[]
     */
    private function getUrlMatches()
    {
        $finder     = new Finder();
        $parentPath = $this->getCurrentProjectDir();
        while (($path = dirname($parentPath)) !== $parentPath) {
            if (file_exists($path . DIRECTORY_SEPARATOR . 'composer.json')) {
                // TODO: Performance Optimieren
                $projects = $finder
                    ->depth('< 8')
                    ->notPath('vendor')
                    ->in($path)
                    ->files()->name('composer.json');

                foreach ($projects as $composerFile) {
                    /* @var $composerFile \SplFileInfo */
                    yield $composerFile->getPath();
                }
                break;
            }
            $parentPath = $path;
        }
    }
}
