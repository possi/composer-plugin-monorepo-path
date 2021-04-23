<?php

namespace Meeva\Monorepo;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;

class Plugin implements PluginInterface, Capable, EventSubscriberInterface
{
    /**
     * @var Repository
     */
    private $repository;

    public function activate(Composer $composer, IOInterface $io)
    {
        $this->repository = new Repository($io, Factory::createConfig(), $composer);
        $composer->getRepositoryManager()->prependRepository($this->repository);
    }

    public static function getSubscribedEvents()
    {
        return [
            PluginEvents::COMMAND => [
                ['onCommand', 0],
            ],
        ];
    }

    public function onCommand(CommandEvent $event)
    {
        if ($event->getInput()->hasOption('no-dev') && $event->getInput()->getOption('no-dev')) {
            $this->repository->disable();
        }
    }

    /**
     * Method by which a Plugin announces its API implementations, through an array
     * with a special structure.
     *
     * The key must be a string, representing a fully qualified class/interface name
     * which Composer Plugin API exposes.
     * The value must be a string as well, representing the fully qualified class name
     * of the implementing class.
     *
     * @tutorial
     *
     * return array(
     *     'Composer\Plugin\Capability\CommandProvider' => 'My\CommandProvider',
     *     'Composer\Plugin\Capability\Validator'       => 'My\Validator',
     * );
     *
     * @return string[]
     */
    public function getCapabilities()
    {
        return [];
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
    }
}
