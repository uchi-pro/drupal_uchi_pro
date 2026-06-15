<?php

namespace Drupal\uchi_pro\Command;

use Drupal\node\Entity\Node;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Drupal\Console\Core\Command\Command;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class DeleteCoursesWithoutThemesCommand extends Command {

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    $this
      ->setName('uchi_pro:delete-courses-without-themes')
      ->setDescription('Удаляет все курсы, у которых нет направлений.');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $output->writeln('Поиск курсов...');

    $nids = \Drupal::entityQuery('node')
      ->condition('type', ['course'], 'IN')
      ->accessCheck(TRUE)
      ->execute();

    $nodes = [];

    $i = 0;
    foreach ($nids as $nid) {
      $node = Node::load($nid);
      if (empty($node->get('field_course_theme')->entity)) {
        $i++;

        $output->writeln("$i. {$node->getTitle()}");
        $nodes[] = $node;
      }
    }

    if (empty($nodes)) {
      $output->writeln('Курсы без направлений не найдены.');
      return;
    }

    $helper = $this->getHelper('question');
    $question = new ConfirmationQuestion('Действительно хотите удалить найденные курсы? (y/N) ', false);

    if (!$helper->ask($input, $output, $question)) {
      $output->writeln('Операция отменена.');
      return;
    }

    foreach ($nodes as $node) {
      $node->delete();
    }

    $output->writeln('Материалы удалены.');
  }
}
