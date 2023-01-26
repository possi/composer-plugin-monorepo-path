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

    private $rootDir = false;

    protected $enabled = true;

    private $currentProjectDir = '';

    /**
     * Initializes path repository.
     */
    public function __construct(IOInterface $io, Config $config, Composer $composer)
    {
        $this->loader         = new ArrayLoader(null, true);
        $this->process        = $composer->getLoop()->getProcessExecutor() ?: new ProcessExecutor($io);
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

    public function getRelativePath($from, $to)
    {
        // some compatibility fixes for Windows paths
        $from = is_dir($from) ? rtrim($from, '\/') . '/' : $from;
        $to   = is_dir($to) ? rtrim($to, '\/') . '/' : $to;
        $from = str_replace('\\', '/', $from);
        $to   = str_replace('\\', '/', $to);

        $from    = explode('/', $from);
        $to      = explode('/', $to);
        $relPath = $to;

        foreach ($from as $depth => $dir) {
            // find first non-matching dir
            if ($dir === $to[$depth]) {
                // ignore this directory
                array_shift($relPath);
            } else {
                // get number of remaining dirs to $from
                $remaining = count($from) - $depth;
                if ($remaining > 1) {
                    // add traversals up to first matching dir
                    $padLength = (count($relPath) + $remaining - 1) * -1;
                    $relPath   = array_pad($relPath, $padLength, '..');
                    break;
                } else {
                    $relPath[0] = './' . $relPath[0];
                }
            }
        }

        return implode('/', $relPath);
    }

    protected function describePackage($dir)
    {
        $dir = $this->getRelativePath($this->currentProjectDir, $dir);
        if ('' === $dir) {
            $dir = './';
        }
        $path = realpath($dir) . DIRECTORY_SEPARATOR;

        $composerFilePath = $path . 'composer.json';
        $json             = file_get_contents($composerFilePath);
        $package          = JsonFile::parseJson($json, $composerFilePath);
        $package['dist']  = [
            'type'      => 'path',
            'url'       => $dir,
            'reference' => sha1($json . serialize($this->options)),
        ];
        $package['transport-options'] = $this->options;

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
            $package['version'] = $versionData['version'] ?? 'dev-master';
        }

        $output = '';
        if (is_dir($path . DIRECTORY_SEPARATOR . '.git') && 0 === $this->process->execute('git log -n1 --pretty=%H', $output, $path)) {
            $package['dist']['reference'] = trim($output);
        }

        return $package;
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

        if ($rootDir = $this->getMonorepoRootDir()) {
            $this->currentProjectDir = $this->getCurrentProjectDir();

            foreach ($this->getUrlMatches() as $url) {
                $package     = $this->describePackage($url);
                $packageData = $this->loader->load($package);
                $this->addPackage($packageData);

                if ('dev-master' !== $package['version']) {
                    // Always Symlink dev-master, independent of current branch
                    $package['version'] = 'dev-master';
                    unset($package['dist']['reference']);
                    $packageData = $this->loader->load($package);
                    $this->addPackage($packageData);
                }
            }
        }
    }

    public function getCurrentProjectDir()
    {
        // TODO: Aus Composer Process bekommen?
        return getcwd();
    }

    /**
     * @return string|null Null if not part of a monorepo
     */
    public function getMonorepoRootDir()
    {
        if (false === $this->rootDir) {
            $this->rootDir = null;
            $parentPath    = $this->getCurrentProjectDir();
            while (($path = dirname($parentPath)) !== $parentPath) {
                if (file_exists($path . DIRECTORY_SEPARATOR . 'composer.json')) {
                    $this->rootDir = $path;
                    break;
                }
                $parentPath = $path;
            }
        }

        return $this->rootDir;
    }

    /**
     * Get a list of all (possibly relative) path names matching given url (supports globbing).
     *
     * @return \Generator|string[]
     */
    private function getUrlMatches()
    {
        if ($path = $this->getMonorepoRootDir()) {
            $finder = new Finder();
            // TODO: Performance Optimieren
            $projects = $finder
                ->depth('> 1')
                ->depth('< 8')
                ->notPath('vendor')
                ->in($path)
                ->files()->name('composer.json');

            foreach ($projects as $composerFile) {
                /* @var $composerFile \SplFileInfo */
                yield $composerFile->getPath();
            }
        }
    }
}
