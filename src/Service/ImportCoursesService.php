<?php

namespace Drupal\uchi_pro\Service;

use Drupal;
use Drupal\Core\Render\Markup;
use Drupal\node\Entity\Node;
use Drupal\uchi_pro\Exception\ImportException;
use Drupal\uchi_pro\Form\SettingsForm;
use Exception;
use UchiPro\ApiClient;
use UchiPro\Courses\Course as ApiCourse;
use UchiPro\Courses\CourseType as ApiCourseType;
use UchiPro\Identity;

class ImportCoursesService
{
  /**
   * @throws ImportException
   */
  public function importCourses()
  {
    if (!$this->settingsExists()) {
      throw new ImportException('Не заполнены настройки для импорта курсов.');
    }

    try {
      $apiCourses = $this->fetchApiCourses();

      if ($this->needImportTypes()) {
        $this->updateTypes($apiCourses);
      }
      $importedThemesNodesByUuids = $this->updateThemes($apiCourses);
      $this->updateCourses($apiCourses, $importedThemesNodesByUuids);
    } catch (Exception $exception) {
      $lastException = $exception;
      watchdog_exception('error', $exception);
      while ($exception = $exception->getPrevious()) {
        watchdog_exception('error', $exception);
      }
      throw new ImportException('Не удалось импортировать курсы.', 0, $lastException);
    }
  }

  private function getSettings()
  {
    return Drupal::config(SettingsForm::SETTINGS);
  }

  /**
   * @return bool
   */
  public function settingsExists()
  {
    $settings = $this->getSettings();
    $url = $settings->get('url');
    $accessToken = $settings->get('access_token');
    return !empty($url) && !empty($accessToken);
  }

  /**
   * @return array|ApiCourse[]
   *
   * @throws Exception
   */
  protected function fetchApiCourses()
  {
    $settings = $this->getSettings();

    $url = $settings->get('url');
    $accessToken = $settings->get('access_token');

    $identity = Identity::createByAccessToken($url, $accessToken);
    $apiClient = ApiClient::create($identity);

    return iterator_to_array($apiClient->courses()->findBy()->getIterator());
  }

  /**
   * @return Node[]
   */
  protected function getTypesNodes(): array
  {
    $nids = Drupal::entityQuery('node')->condition('type','training_type')->execute();
    $nodes = Node::loadMultiple($nids);

    $nodesByIds = [];

    foreach ($nodes as $node) {
      $trainingTypeId = $node->get('field_training_type_id')->getString();
      $nodesByIds[$trainingTypeId] = $node;
    }

    return $nodesByIds;
  }

  /**
   * @return Node[]
   */
  protected function getThemesNodesByUuids(): array
  {
    $nids = Drupal::entityQuery('node')->condition('type','theme')->execute();
    $nodes = Node::loadMultiple($nids);

    $nodesByIds = [];

    foreach ($nodes as $node) {
      $themeId = $node->get('field_theme_id')->getString();
      if (!empty($themeId)) {
        $nodesByIds[$themeId] = $node;
      }
    }

    return $nodesByIds;
  }

  /**
   * @return Node[]
   */
  protected function getCoursesNodesByUuids(): array
  {
    $nids = Drupal::entityQuery('node')->condition('type','course')->execute();
    $nodes = Node::loadMultiple($nids);

    $nodesByIds = [];

    foreach ($nodes as $node) {
      $courseId = $node->get('field_course_id')->getString();
      if (!empty($courseId)) {
        $nodesByIds[$courseId] = $node;
      }
    }

    return $nodesByIds;
  }

  /**
   * @param array|ApiCourse[] $apiCourses
   *
   * @return array|Node[]
   */
  protected function updateTypes(array $apiCourses)
  {
    $typesNodes = $this->getTypesNodes();

    foreach ($apiCourses as $apiCourse) {
      if (empty($apiCourse->type->id)) {
        continue;
      }

      $type = $apiCourse->type;

      if (isset($typesNodes[$type->id])) {
        $node = $typesNodes[$type->id];
      } else {
        $node = Node::create([
          'type' => 'training_type',
          'title' => $type->title,
          'field_training_type_id' => ['value' => $type->id],
        ]);
      }

      $node->save();

      $typesNodes[$type->id] = $node;
    }

    return $typesNodes;
  }

  /**
   * @param array|ApiCourse[] $apiCourses
   *
   * @return array|Node[]
   */
  protected function updateThemes(array $apiCourses): array
  {
    $themesNodesByUuids = $this->getThemesNodesByUuids();
    $themesForIgnore = array_keys($themesNodesByUuids);

    foreach ($this->getThemes($apiCourses) as $apiCourse) {
      if (isset($themesNodesByUuids[$apiCourse->id])) {
        $node = $themesNodesByUuids[$apiCourse->id];
        unset($themesForIgnore[array_search($apiCourse->id, $themesForIgnore)]);
      } else {
        $node = Node::create([
          'type' => 'theme',
          'title' => mb_substr($apiCourse->title, 0, 250),
          'field_theme_id' => ['value' => $apiCourse->id],
        ]);
      }

      if (isset($themesNodesByUuids[$apiCourse->parentId])) {
        $node->set('field_theme_parent', ['target_id' => $themesNodesByUuids[$apiCourse->parentId]->id()]);
      } else {
        $node->get('field_theme_parent')->setValue([]);
      }

      $node->save();

      $themesNodesByUuids[$apiCourse->id] = $node;
    }

    foreach ($themesForIgnore as $uuid) {
      $themeNode = $themesNodesByUuids[$uuid];
      if (!empty($themeNode) && $themeNode->isPublished() && $themeNode->get('field_theme_id')->getString()) {
        $themeNode->setUnpublished()->save();
        $this->warning("Направление <a href=\"/node/{$themeNode->id()}/edit\" target=\"_blank\">{$themeNode->getTitle()}</a> снято с публикации.");
      }

      unset($themesNodesByUuids[$uuid]);
    }

    return $themesNodesByUuids;
  }

  /**
   * @param array|ApiCourse[] $apiCourses
   * @param ?string $parentId
   *
   * @return array
   */
  private function getThemes(array $apiCourses, ?string $parentId = null): array
  {
    $themes = [];

    $ignoredVendorThemesIds = $this->getIgnoredVendorThemesIds();
    $ignoredServiceThemesIds = $this->getIgnoredServiceThemesIds();

    foreach ($apiCourses as $apiCourse) {
      $isTheme = $apiCourse->parentId === $parentId && $apiCourse->lessonsCount === 0 && $apiCourse->childrenCount > 0;
      if (!$isTheme) {
        continue;
      }
      $isIgnoredTheme = in_array($apiCourse->id, $ignoredVendorThemesIds);
      if ($isIgnoredTheme) {
        $this->warning("Направление <a href=\"{$this->getApiCourseUrl($apiCourse)}\" target='_blank'>{$apiCourse->title}</a> пропущено согласно настройкам интеграции.");
        continue;
      }
      $isIgnoredServiceTheme = in_array($apiCourse->id, $ignoredServiceThemesIds);
      if ($isIgnoredServiceTheme) {
        continue;
      }

      $themes[] = $apiCourse;
      foreach ($this->getThemes($apiCourses, $apiCourse->id) as $theme) {
        $themes[] = $theme;
      }
    }

    return $themes;
  }

  /**
   * @return string[]
   */
  private function getIgnoredVendorThemesIds(): array
  {
    $themesIds = [];

    $settings = $this->getSettings();
    foreach (explode("\n", (string)$settings->get('ignored_themes_ids')) as $line) {
      $themeId = trim(substr($line, 0, 36));
      if ($themeId) {
        $themesIds[] = $themeId;
      }
    };

    return $themesIds;
  }

  /**
   * @return string[]
   */
  private function getIgnoredServiceThemesIds(): array
  {
    return [
      'a74d99dd-b941-404d-ba1b-6eb40cc4dc61', // Конструктор курсов
    ];
  }

  /**
   * @return string[]
   */
  private function getIgnoredThemesIds(): array
  {
    return array_merge($this->getIgnoredVendorThemesIds(), $this->getIgnoredServiceThemesIds());
  }

  /**
   * @param array|ApiCourse[] $apiCourses
   * @param array|Node[] $importedThemesNodesByUuids
   *
   * @return array|Node[]
   */
  protected function updateCourses(array $apiCourses, array $importedThemesNodesByUuids): array
  {
    $settings = $this->getSettings();

    $needPublishCoursesOnImport = $settings->get('publish_courses_on_import');
    $needUpdateCoursesTitles = $settings->get('update_courses_titles');
    $needUpdateCoursesDescriptions = $settings->get('update_courses_descriptions');
    $needUpdateCoursesPrices = $settings->get('update_courses_prices');
    $needImportTypes = $this->needImportTypes();

    $coursesNodesByUuids = $this->getCoursesNodesByUuids();
    $allThemesNodesByUuids = $this->getThemesNodesByUuids();
    $typesNodesByIds = $this->getTypesNodes();

    $ignoredThemesIds = $this->getIgnoredThemesIds();

    $coursesForUnpublishUuids = array_keys($coursesNodesByUuids);

    $suitableApiCourses = array_filter($apiCourses, function (ApiCourse $apiCourse) use ($allThemesNodesByUuids, $importedThemesNodesByUuids, $ignoredThemesIds) {
      $isTheme = isset($allThemesNodesByUuids[$apiCourse->id]);
      if ($isTheme) {
        return FALSE;
      }
      $isParentThemeExists = isset($importedThemesNodesByUuids[$apiCourse->parentId]);
      if (!$isParentThemeExists) {
        return FALSE;
      }
      if (in_array($apiCourse->id, $ignoredThemesIds)) {
        return FALSE;
      }
      $hasLessons = $apiCourse->lessonsCount > 0;
      if (!$hasLessons) {
        $this->warning("Курс <a href=\"{$this->getApiCourseUrl($apiCourse)}\" target=\"_blank\">{$apiCourse->title}</a> пропущен: не содержит уроков.");
        return FALSE;
      }
      return TRUE;
    });

    $updatedCount = 0;
    foreach ($suitableApiCourses as $apiCourse) {
      $apiCourse = clone $apiCourse;
      $apiCourse->title = mb_substr($apiCourse->title, 0, 2000);

      $courseNode = null;
      if (isset($coursesNodesByUuids[$apiCourse->id])) {
        $courseNode = $coursesNodesByUuids[$apiCourse->id];
        unset($coursesForUnpublishUuids[array_search($apiCourse->id, $coursesForUnpublishUuids)]);
      }
      $isNew = empty($courseNode);
      $needSave = FALSE;

      $previousApiCourse = $courseNode ? $this->createApiCourseByNode($courseNode) : new ApiCourse();

      $shortTitle = mb_substr($apiCourse->title, 0, 250);
      $price = $apiCourse->price ?? 0;

      if (!$courseNode) {
        $needSave = true;
        $courseNode = Node::create([
          'type' => 'course',
          'status' => $needPublishCoursesOnImport ? 1 : 0,
          'title' => $shortTitle,
          'field_course_title' => ['value' => $apiCourse->title],
          'field_course_description' => [
              'value' => $apiCourse->description,
              'format' => 'full_html',
            ],
          'field_course_id' => ['value' => $apiCourse->id],
          'field_course_price' => ['value' => $price],
        ]);
      }

      if ($apiCourse->parentId != $previousApiCourse->parentId) {
        $fixTheme = (bool)$courseNode->get('field_course_fix_theme')->getString();
        if (!$fixTheme) {
          $needSave = true;
          $courseTheme = $importedThemesNodesByUuids[$apiCourse->parentId];
          $courseNode->set('field_course_theme', [
            'entity' => $courseTheme,
          ]);
        }
      }

      if ($needImportTypes) {
        $typeAppeared = empty($previousApiCourse->type->id) && !empty($apiCourse->type->id);
        $typeFaded = !empty($previousApiCourse->type->id) && empty($apiCourse->type->id);
        $typesChanged = !empty($previousApiCourse->type->id) && !empty($apiCourse->type->id) && ($previousApiCourse->type->id != $apiCourse->type->id);
        if ($typeAppeared || $typeFaded || $typesChanged) {
          $needSave = true;
          if ($typeFaded) {
            $courseNode->set('field_course_training_type', null);
          } else {
            $courseType = $typesNodesByIds[$apiCourse->type->id];
            $courseNode->set('field_course_training_type', [
              'entity' => $courseType,
            ]);
          }
        }
      }

      if ($needUpdateCoursesTitles && ($apiCourse->title != $previousApiCourse->title)) {
        $needSave = true;
        $courseNode->set('title', $shortTitle);
        $courseNode->set('field_course_title', $apiCourse->title);
      }

      if ($needUpdateCoursesDescriptions && ($apiCourse->description != $previousApiCourse->description)) {
        $needSave = true;
        // Передаем массив с ключами value и format
        $courseNode->set('field_course_description', [
          'value' => $apiCourse->description,
          'format' => 'full_html',
        ]);
      }

      if ($needUpdateCoursesPrices && ($price != $previousApiCourse->price)) {
        $needSave = true;
        $courseNode->set('field_course_price', ['value' => $apiCourse->price]);
      }

      if ($apiCourse->hours != $previousApiCourse->hours) {
        $needSave = true;
        $courseNode->set('field_course_hours', ['value' => $apiCourse->hours]);
      }

      $serializedPlan = $this->getSerializedPlan($apiCourse);
      $previousSerializedPlan = $courseNode->get('field_course_plan')->getString();
      if ($serializedPlan != $previousSerializedPlan) {
        $needSave = true;
        $courseNode->set('field_course_plan', $serializedPlan ? ['value' => $serializedPlan] : null);
      }

      if ($needSave) {
        $updatedCount++;

        $courseNode->save();

        $this->status("Курс <a href=\"/node/{$courseNode->id()}/edit\" target=\"_blank\">{$courseNode->get('field_course_title')->getString()}</a> " . ($isNew ? ' импортирован' : 'обновлен') . '.');
      }

      $coursesNodesByUuids[$apiCourse->id] = $courseNode;
    }

    $unpublishedCount = 0;
    foreach ($coursesForUnpublishUuids as $courseId) {
      $courseNode = $coursesNodesByUuids[$courseId];

      if ($courseNode->isPublished()) {
        $isCourseLocked = (bool)$courseNode->get('field_course_locked');
        if (!$isCourseLocked) {
          $unpublishedCount++;
          $courseNode->setUnpublished();
          $courseNode->save();

          $this->status("Курс <a href=\"/node/{$courseNode->id()}/edit\" target=\"_blank\">{$courseNode->get('field_course_title')->getString()}</a> снят с публикации.");
        } else {
          $this->status("Курс <a href=\"/node/{$courseNode->id()}/edit\" target=\"_blank\">{$courseNode->get('field_course_title')->getString()}</a> не снят с публикации т.к. защищён.");
        }
      }
    }

    $this->log("Обновлено курсов: {$updatedCount}");
    $this->log("Снято с публикации курсов: {$unpublishedCount}");

    return $coursesNodesByUuids;
  }

  private function status($messageText)
  {
    $message = Markup::create($messageText);
    $messanger = Drupal::messenger();
    $messanger->addMessage($message, $messanger::TYPE_STATUS);
  }

  private function warning($messageText)
  {
    $message = Markup::create($messageText);
    $messanger = Drupal::messenger();
    $messanger->addMessage($message, $messanger::TYPE_WARNING);
  }

  private function log($message)
  {
    $this->status($message);
    Drupal::logger('uchi_pro')->info($message);
  }

  private function getApiCourseUrl(ApiCourse $apiCourse)
  {
    $settings = $this->getSettings();

    $url = $settings->get('url');

    return "{$url}/courses/{$apiCourse->id}";
  }

  private function createApiCourseByNode(Node $node)
  {
    $apiCourse = new ApiCourse();

    $apiCourse->id = $node->get('field_course_id')->getString();
    if (isset($node->get('field_course_theme')->entity)) {
      $apiCourse->parentId = $node->get('field_course_theme')->entity->get('field_theme_id')->getString();
    }
    if (isset($node->get('field_course_training_type')->entity)) {
      $type = new ApiCourseType();
      $type->id = $node->get('field_course_training_type')->entity->get('field_training_type_id')->getString();
      $apiCourse->type = $type;
    }
    $apiCourse->title = $node->get('field_course_title')->getString();
    $apiCourse->description = $node->get('field_course_description')->getString();
    $apiCourse->price = $node->get('field_course_price')->getString();
    $apiCourse->hours = $node->get('field_course_hours')->getString();

    return $apiCourse;
  }

  private function getSerializedPlan(ApiCourse $apiCourse)
  {
    $plan = [];

    if (!empty($apiCourse->academicPlan)) {
      foreach ($apiCourse->academicPlan->items as $item) {
        $plan[] = [
          'title' => $item->title,
          'hours' => $item->hours,
          'type' => $item->type->title,
        ];
      }
    }

    return !empty($plan) ? serialize($plan) : null;
  }

  /**
   * @return bool
   */
  private function needImportTypes()
  {
    $needImportTypes = $this->getSettings()->get('import_types');
    if (is_null($needImportTypes)) {
      $needImportTypes = true;
    }
    return $needImportTypes;
  }
}
