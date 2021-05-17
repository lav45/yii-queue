<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Queue\Adapter;

use InvalidArgumentException;
use Yiisoft\Yii\Queue\Enum\JobStatus;
use Yiisoft\Yii\Queue\Message\Behaviors\ExecutableBehaviorInterface;
use Yiisoft\Yii\Queue\Message\MessageInterface;
use Yiisoft\Yii\Queue\Worker\WorkerInterface;

final class SynchronousAdapter implements AdapterInterface
{
    private const BEHAVIORS_AVAILABLE = [];
    private array $messages = [];
    private WorkerInterface $worker;
    private int $current = 0;
    private ?BehaviorChecker $behaviorChecker;

    public function __construct(WorkerInterface $worker, ?BehaviorChecker $behaviorChecker = null)
    {
        $this->worker = $worker;
        $this->behaviorChecker = $behaviorChecker;
    }

    public function __destruct()
    {
        $this->runExisting([$this->worker, 'process']);
    }

    public function runExisting(callable $callback): void
    {
        while (isset($this->messages[$this->current])) {
            $callback($this->messages[$this->current]);
            unset($this->messages[$this->current]);
            $this->current++;
        }
    }

    public function status(string $id): JobStatus
    {
        $id = (int) $id;

        if ($id < 0) {
            throw new InvalidArgumentException('This adapter IDs start with 0.');
        }

        if ($id < $this->current) {
            return JobStatus::done();
        }

        if (isset($this->messages[$id])) {
            return JobStatus::waiting();
        }

        throw new InvalidArgumentException('There is no message with the given ID.');
    }

    public function push(MessageInterface $message): void
    {
        $behaviors = $message->getBehaviors();
        if ($this->behaviorChecker !== null) {
            $this->behaviorChecker->check(self::class, $behaviors, self::BEHAVIORS_AVAILABLE);
        }

        foreach ($behaviors as $behavior) {
            if ($behavior instanceof ExecutableBehaviorInterface) {
                $behavior->execute();
            }
        }

        $key = count($this->messages) + $this->current;
        $this->messages[] = $message;

        $message->setId((string) $key);
    }

    public function subscribe(callable $handler): void
    {
        $this->runExisting($handler);
    }
}
