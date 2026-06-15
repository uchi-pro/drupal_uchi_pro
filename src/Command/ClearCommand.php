<?php

namespace Drupal\uchi_pro\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Drupal\Console\Core\Command\Command;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class ClearCommand extends Command {

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    $this
      ->setName('uchi_pro:clear')
      ->setDescription('Удаляет все ноды типов обучения, направлений обучения, курсов.');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $helper = $this->getHelper('question');
    $question = new ConfirmationQuestion('Действительно хотите удалить все курсы, направления и типы обучения? (y/N) ', false);

    if (!$helper->ask($input, $output, $question)) {
      $output->writeln('Операция отменена.');
      return;
    }

    $output->writeln('Удаление материалов...');

    $nids = \Drupal::entityQuery('node')
      ->condition('type', ['course', 'theme', 'training_type'], 'IN')
      ->accessCheck(TRUE)
      ->execute();

    $storage_handler = \Drupal::entityTypeManager()->getStorage('node');
    $entities = $storage_handler->loadMultiple($nids);
    $storage_handler->delete($entities);

    $output->writeln('Материалы удалены.');
  }
}
