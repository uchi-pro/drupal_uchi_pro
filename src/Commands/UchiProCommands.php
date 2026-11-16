<?php

namespace Drupal\uchi_pro\Commands;

use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Drush\Exceptions\UserAbortException;

class UchiProCommands extends DrushCommands {

  const CLEAR = 'uchi_pro:clear';
  const DELETE_COURSES_WITHOUT_THEMES = 'uchi_pro:delete-courses-without-themes';

  #[CLI\Command(name: self::CLEAR, aliases: ['up-clear'])]
  #[CLI\Help(description: 'Удаляет все ноды типов обучения, направлений обучения и курсов.')]
  #[CLI\Bootstrap(level: \Drush\Boot\DrupalBootLevels::FULL)]
  public function clear(): void {
    $this->output()->writeln('Будут удалены все курсы, направления и типы обучения.');
    if (!$this->io()->confirm('Действительно хотите удалить все курсы, направления и типы обучения?', false)) {
      throw new UserAbortException();
    }

    $nids = \Drupal::entityQuery('node')
      ->condition('type', ['course', 'theme', 'training_type'], 'IN')
      ->accessCheck(TRUE)
      ->execute();

    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $entities = $storage->loadMultiple($nids);
    $storage->delete($entities);

    $this->logger()->success('Материалы удалены.');
  }

  #[CLI\Command(name: self::DELETE_COURSES_WITHOUT_THEMES, aliases: ['up-delete-courses-without-themes'])]
  #[CLI\Help(description: 'Удаляет все курсы, у которых нет направлений.')]
  #[CLI\Bootstrap(level: \Drush\Boot\DrupalBootLevels::FULL)]
  public function deleteCoursesWithoutThemes(): void {
    $this->output()->writeln('Поиск курсов...');

    $nids = \Drupal::entityQuery('node')
      ->condition('type', 'course')
      ->accessCheck(TRUE)
      ->execute();

    $nodes = [];
    $i = 0;
    foreach ($nids as $nid) {
      $node = \Drupal\node\Entity\Node::load($nid);
      if (empty($node->get('field_course_theme')->entity)) {
        $i++;
        $this->output()->writeln("{$i}. {$node->getTitle()}");
        $nodes[] = $node;
      }
    }

    if (empty($nodes)) {
      $this->logger()->success('Курсы без направлений не найдены.');
      return;
    }

    if (!$this->io()->confirm('Действительно хотите удалить найденные курсы?', false)) {
      throw new UserAbortException();
    }

    foreach ($nodes as $node) {
      $node->delete();
    }

    $this->logger()->success('Материалы удалены.');
  }
}
