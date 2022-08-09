<?php
/**
 * Provides methods to interact with Codeception `run` command event dispatch stack.
 *
 * @package lucatume\WPBrowser\Module\Traits
 */

namespace lucatume\WPBrowser\Module\Traits;

use Codeception\Application;
use Codeception\Codecept;
use Codeception\Exception\ModuleException;
use Codeception\Lib\ModuleContainer;
use Codeception\Util\ReflectionHelper;
use ReflectionException;
use Symfony\Component\Console\Application as SymfonyApp;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Trait EventListener
 *
 * @package lucatume\WPBrowser\Module\Traits
 * @property ModuleContainer $moduleContainer
 */
trait WithEvents
{

    /**
     * The running command event dispatcher or the global one built and shared among all instances.
     *
     * @var EventDispatcher
     */
    protected static EventDispatcher $dispatcher;

    /**
     * Adds a callback to be performed on a global runner event..
     *
     * @param string $event     The event to run the callback on.
     * @param callable $callback  The callback to run on the event.
     * @param int $priority  The priority that will be assigned to the callback in the context of the event.
     *
     * @throws ModuleException If the event dispatcher cannot be found or built.
     */
    protected function addAction(string $event, callable $callback, int $priority = 0): void
    {
        $this->getEventDispatcher()->addListener($event, $callback, $priority);
    }

    /**
     * Returns the instance of the event dispatcher used by the currently running command instance.
     *
     * If no command is currently running, then a shared event dispatcher is built and will be returned to all
     * the classes using the trait.
     *
     * @return EventDispatcher The event dispatcher instance used by the running command or one created ad-hoc.
     *                         The event dispatcher instance is shared by all instances implementing the trait.
     *
     * @throws ModuleException If the global application instance is not a Codeception\Application instance; if the
     *                         `run` command dispatcher property cannot be accessed or is not an `EventDispatcher`
     *                         instance.
     */
    protected function getEventDispatcher(): EventDispatcher
    {
        if (static::$dispatcher instanceof EventDispatcher) {
            return static::$dispatcher;
        }

        global $app;

        if ($app instanceof Application) {
            try {
                $runningCommand = ReflectionHelper::readPrivateProperty($app, 'runningCommand', SymfonyApp::class);

                if (!$runningCommand instanceof Command) {
                    throw new ModuleException(
                        $this,
                        'Running command is empty or not an instance of the ' .
                        'Symfony\Component\Console\Command\Command class.'
                    );
                }

                $codecept = ReflectionHelper::readPrivateProperty($runningCommand, 'codecept');

                if (!$codecept instanceof Codecept) {
                    throw new ModuleException(
                        $this,
                        'Running command $codecept property is not set.'
                    );
                }

                static::$dispatcher = $codecept->getDispatcher();
            } catch (ReflectionException $e) {
                throw new ModuleException(
                    $this,
                    'Could not get the value of the `\Codeception\Command\Run::$codecept` property, message:' .
                    $e->getMessage()
                );
            }
        } elseif (!static::$dispatcher instanceof EventDispatcher) {
            static::$dispatcher = new EventDispatcher();
        }

        if (!static::$dispatcher instanceof EventDispatcher) {
            throw new ModuleException($this, sprintf(
                '\\Codeception\\Codecept::$eventDispatcher property is not an instance of %s; value is instead: %s',
                EventDispatcher::class,
                print_r(static::$dispatcher, true)
            ));
        }

        return static::$dispatcher;
    }
}
