<?php

namespace App\Support\Markdown\Tasks;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\ExtensionInterface;

/**
 * The `tasks` block (app/Support/Markdown/Tasks/TasksProcessor.php): a to-do table
 * that reads live work items from the board instead of a hand-kept copy of them.
 */
final class TasksExtension implements ExtensionInterface
{
    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addEventListener(DocumentParsedEvent::class, new TasksProcessor, -30);
        $environment->addRenderer(TasksNode::class, new TasksRenderer, 10);
    }
}
