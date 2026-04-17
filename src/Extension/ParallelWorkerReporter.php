<?php

namespace lucatume\WPBrowser\Extension;

use Codeception\Event\FailEvent;
use Codeception\Events;
use Codeception\Extension;

class ParallelWorkerReporter extends Extension
{
    /** @var array<string,string> */
    public static array $events = [
        Events::TEST_SUCCESS    => 'onSuccess',
        Events::TEST_FAIL       => 'onFail',
        Events::TEST_ERROR      => 'onError',
        Events::TEST_SKIPPED    => 'onSkipped',
        Events::TEST_INCOMPLETE => 'onIncomplete',
        Events::TEST_WARNING    => 'onWarning',
        Events::TEST_USELESS    => 'onUseless',
    ];

    /** @var resource|null */
    private $stream = null;

    public function _initialize(): void
    {
        $this->_reconfigure(['settings' => ['silent' => true]]);
        $path = getenv('WPBROWSER_PARALLEL_EVENT_FILE');
        if ($path === false || $path === '') {
            return;
        }
        $handle = @fopen($path, 'ab');
        if ($handle !== false) {
            $this->stream = $handle;
        }
    }

    public function onSuccess(): void
    {
        $this->emit('.');
    }

    public function onFail(FailEvent $event): void
    {
        $this->emit('F');
    }

    public function onError(FailEvent $event): void
    {
        $this->emit('E');
    }

    public function onSkipped(): void
    {
        $this->emit('S');
    }

    public function onIncomplete(): void
    {
        $this->emit('I');
    }

    public function onWarning(): void
    {
        $this->emit('W');
    }

    public function onUseless(): void
    {
        $this->emit('U');
    }

    private function emit(string $char): void
    {
        if ($this->stream !== null) {
            fwrite($this->stream, $char);
            fflush($this->stream);
        }
    }
}
