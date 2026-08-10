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
   * Загружает все ноды типов обучения, ключуя по training_type_id.
   *
   * @return Node[]
   */
  protected function getTypesNodes(): array
  {
    $nids = Drupal::entityQuery('node')->condition('type','training_type')->accessCheck(FALSE)->execute();
    $nodes = Node::loadMultiple($nids);

    $nodesByIds = [];

    foreach ($nodes as $node) {
      $trainingTypeId = $node->get('field_training_type_id')->getString();
      $nodesByIds[$trainingTypeId] = $node;
    }

    return $nodesByIds;
  }

  /**
   * Загружает все ноды направлений, ключуя по theme_id (UUID из СДО).
   *
   * @return Node[]
   */
  protected function getThemesNodesByUuids(): array
  {
    $nids = Drupal::entityQuery('node')->condition('type','theme')->accessCheck(FALSE)->execute();
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
   * Загружает все ноды курсов, ключуя по course_id (UUID из СДО).
   *
   * @return Node[]
   */
  protected function getCoursesNodesByUuids(): array
  {
    $nids = Drupal::entityQuery('node')->condition('type','course')->accessCheck(FALSE)->execute();
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
   * Обновляет типы обучения (training_type) из API.
   *
   * Для каждого типа из API создаёт ноду типа обучения, если её нет.
   * Существующие ноды просто сохраняются для обновления.
   *
   * @param array|ApiCourse[] $apiCourses
   * @return array|Node[]
   */
  protected function updateTypes(array $apiCourses)
  {
    $typesNodes = $this->getTypesNodes();

    foreach ($apiCourses as $apiCourse) {
      // У каждого типа обучения должен быть id.
      if (empty($apiCourse->type->id)) {
        continue;
      }

      $type = $apiCourse->type;

      // Если нода типа уже есть — берём её, иначе создаём новую.
      if (isset($typesNodes[$type->id])) {
        $node = $typesNodes[$type->id];
      } else {
        $node = Node::create([
          'type' => 'training_type',
          'title' => $type->title,
          'field_training_type_id' => ['value' => $type->id],
        ]);
      }

      // Сохраняем — это обновляет ноду.
      $node->save();

      $typesNodes[$type->id] = $node;
    }

    return $typesNodes;
  }

  /**
   * Обновляет направления.
   *
   * @param array|ApiCourse[] $apiCourses
   *
   * @return array|Node[] Массив нод направлений. Ключи uuid направления.
   */
  protected function updateThemes(array $apiCourses): array
  {
    $themesNodesByUuids = $this->getThemesNodesByUuids();
    $ignoredThemesIds = $this->getIgnoredThemesIds();
    // Сначала все направления — кандидаты на снятие с публикации.
    $themesForUnpublish = array_keys($themesNodesByUuids);

    // Не трогаем направления, стоящие в исключениях,
    // и все их дочерние направления (рекурсивно).
    $collectIgnoredChildren = function (string $parentId) use (&$collectIgnoredChildren, $apiCourses): array {
      $children = [];
      foreach ($apiCourses as $apiCourse) {
        if ($apiCourse->parentId === $parentId) {
          $children[] = $apiCourse->id;
          $children = array_merge($children, $collectIgnoredChildren($apiCourse->id));
        }
      }
      return $children;
    };
    foreach ($ignoredThemesIds as $ignoredId) {
      $idx = array_search($ignoredId, $themesForUnpublish);
      if ($idx !== false) {
        unset($themesForUnpublish[$idx]);
      }
      foreach ($collectIgnoredChildren($ignoredId) as $childId) {
        $idx = array_search($childId, $themesForUnpublish);
        if ($idx !== false) {
          unset($themesForUnpublish[$idx]);
        }
      }
    }

    $themesForUnpublish = array_values($themesForUnpublish);

    // Обрабатываем только те темы, которые прошли фильтр getThemes.
    foreach ($this->getThemes($apiCourses) as $apiCourse) {
      // Если тема уже есть — обновляем, иначе создаём.
      $needSaveTheme = false;
      $newTitle = mb_substr($apiCourse->title, 0, 250);
      $themeChangedFields = [];
      if (isset($themesNodesByUuids[$apiCourse->id])) {
        $node = $themesNodesByUuids[$apiCourse->id];
        // Обновляем название, если оно изменилось.
        if ($node->getTitle() !== $newTitle) {
          $node->set('title', $newTitle);
          $themeChangedFields[] = 'title';
          $needSaveTheme = true;
        }
        unset($themesForUnpublish[array_search($apiCourse->id, $themesForUnpublish)]);
      } else {
        $node = Node::create([
          'type' => 'theme',
          'title' => $newTitle,
          'field_theme_id' => ['value' => $apiCourse->id],
        ]);
        $themeChangedFields[] = 'created';
        $needSaveTheme = true;
      }

      // Устанавливаем родительскую тему, если она импортирована.
      $currentParent = $node->get('field_theme_parent')->entity;
      $expectedParent = isset($themesNodesByUuids[$apiCourse->parentId]) ? $themesNodesByUuids[$apiCourse->parentId] : null;
      if ($currentParent !== $expectedParent) {
        if ($expectedParent) {
          $node->set('field_theme_parent', ['target_id' => $expectedParent->id()]);
        } else {
          $node->get('field_theme_parent')->setValue([]);
        }
        $themeChangedFields[] = 'parent';
        $needSaveTheme = true;
      }

      if ($needSaveTheme) {
        $node->save();
        $fieldList = implode(', ', $themeChangedFields);
        $apiUrl = $this->getApiCourseUrlById($apiCourse->id);
        $this->status("Направление <a href=\"/node/{$node->id()}/edit\" target=\"_blank\">{$node->getTitle()}</a> ($fieldList) (<a href=\"{$apiUrl}\" target=\"_blank\">СДО</a>).");
      }

      $themesNodesByUuids[$apiCourse->id] = $node;
    }

    // Снимаем с публикации направления, которых нет в API.
    foreach ($themesForUnpublish as $uuid) {
      $themeNode = $themesNodesByUuids[$uuid];
      // Не снимаем, если тема не опубликована или у неё нет id.
      if (!empty($themeNode) && $themeNode->isPublished() && $themeNode->get('field_theme_id')->getString()) {
        $themeNode->setUnpublished()->save();
        $this->warning("Направление <a href=\"/node/{$themeNode->id()}/edit\" target=\"_blank\">{$themeNode->getTitle()}</a> снято с публикации.");
      }

      unset($themesNodesByUuids[$uuid]);
    }

    return $themesNodesByUuids;
  }

  /**
   * Рекурсивно собирает список курсов, которые являются направлениями.
   *
   * Направление — это узел СДО, у которого:
   * - parentId совпадает с переданным (или null для корневых)
   * - lessonsCount === 0 (нет своих уроков)
   * - childrenCount > 0 (есть дочерние элементы)
   *
   * Игнорирует направления из исключений (vendor и service).
   *
   * @param array|ApiCourse[] $apiCourses
   * @param string|null $parentId UUID родительского направления (null для корня)
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
      // Каждая строка: UUID в начале, потом optional комментарий.
      // Если строка начинается с # — это комментарий, пропускаем.
      $trimmed = ltrim($line);
      if ($trimmed === '' || strpos($trimmed, '#') === 0) {
        continue;
      }
      // Ищем UUID-паттерн в начале строки.
      if (preg_match('/^([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i', $trimmed, $matches)) {
        $themesIds[] = $matches[1];
      }
    };

    return $themesIds;
  }

  /**
   * Возвращает список сервисных направлений, которые всегда игнорируются.
   *
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

    // Настройки, которые влияют на импорт.
    $needPublishCoursesOnImport = $settings->get('publish_courses_on_import');
    $ignoredThemesIds = $this->getIgnoredThemesIds();

    // Загружаем все существующие ноды курсов, тем и типов — чтобы не делать запрос в БД на каждый курс.
    $coursesNodesByUuids = $this->getCoursesNodesByUuids();
    $allThemesNodesByUuids = $this->getThemesNodesByUuids();
    $typesNodesByIds = $this->getTypesNodes();

    // Определяем, какие курсы в API относятся к игнорируемым направлениям.
    $coursesInIgnoredThemes = $this->getCoursesInIgnoredThemes($apiCourses, $ignoredThemesIds);

    // По умолчанию все существующие курсы — кандидаты на снятие с публикации.
    $coursesForUnpublishUuids = array_keys($coursesNodesByUuids);

    // Фильтруем курсы API: оставляем только пригодные для импорта.
    $suitableApiCourses = $this->getSuitableApiCourses($apiCourses, $allThemesNodesByUuids, $importedThemesNodesByUuids, $ignoredThemesIds);

    // Исключаем курсы из игнорируемых тем из общего цикла — они обрабатываются отдельно.
    $ignoredSet = array_flip($coursesInIgnoredThemes);
    $suitableApiCourses = array_filter($suitableApiCourses, function (object $apiCourse) use ($ignoredSet) {
      return !isset($ignoredSet[$apiCourse->id]);
    });

    // === Основной цикл: обрабатываем каждый подходящий курс из API ===
    $updatedCount = 0;
    foreach ($suitableApiCourses as $apiCourse) {
      $apiCourse = clone $apiCourse;
      $apiCourse->title = mb_substr($apiCourse->title, 0, 2000);

      // Ищем существующую ноду по UUID курса из СДО.
      $courseNode = null;
      if (isset($coursesNodesByUuids[$apiCourse->id])) {
        $courseNode = $coursesNodesByUuids[$apiCourse->id];
        // Курс найден — убираем из списка на снятие с публикации.
        unset($coursesForUnpublishUuids[array_search($apiCourse->id, $coursesForUnpublishUuids)]);
      }
      $isNew = empty($courseNode);

      // Создаём «виртуальный» ApiCourse из данных Drupal — для сравнения.
      $previousApiCourse = $courseNode ? $this->createApiCourseByNode($courseNode) : new ApiCourse();

      $shortTitle = mb_substr($apiCourse->title, 0, 250);
      $apiPrice = $apiCourse->price;
      $price = $apiPrice ?? 0;

      // Создаём новую ноду, если курс ещё не импортирован.
      if (!$courseNode) {
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

      // Собираем список изменившихся полей.
      $changedFields = [];
      $this->updateCourseTheme($apiCourse, $previousApiCourse, $courseNode, $importedThemesNodesByUuids, $changedFields);
      $this->updateCourseType($apiCourse, $previousApiCourse, $courseNode, $typesNodesByIds, $changedFields);
      $this->updateCourseFields($apiCourse, $previousApiCourse, $courseNode, $shortTitle, $price, $apiPrice, $changedFields);

      // Восстанавливаем публикацию, если курс был снят (например, раньше был в исключениях).
      if (!$courseNode->isPublished()) {
        $isCourseLocked = (bool)$courseNode->get('field_course_locked')->getValue();
        if (!$isCourseLocked) {
          $changedFields[] = 'status';
          $courseNode->setPublished();
        }
      }

      // Сохраняем только если есть изменения.
      if (!empty($changedFields)) {
        $updatedCount++;

        $courseNode->save();

        $fieldList = implode(', ', $changedFields);
        $apiUrl = $this->getApiCourseUrl($apiCourse);
        $this->status("Курс <a href=\"/node/{$courseNode->id()}/edit\" target=\"_blank\">{$courseNode->get('field_course_title')->getString()}</a> " . ($isNew ? 'импортирован' : "обновлен (изменилось: {$fieldList})") . " (<a href=\"{$apiUrl}\" target=\"_blank\">СДО</a>).");
      }

      $coursesNodesByUuids[$apiCourse->id] = $courseNode;
    }

    // Исключаем курсы из игнорируемых тем из списка на снятие — они обрабатываются отдельно.
    $coursesInIgnoredThemesSet = array_flip($coursesInIgnoredThemes);
    foreach ($coursesForUnpublishUuids as $courseId) {
      if (isset($coursesInIgnoredThemesSet[$courseId])) {
        unset($coursesForUnpublishUuids[array_search($courseId, $coursesForUnpublishUuids)]);
      }
    }

    // Не снимаем курсы, у которых уже есть импортированная тема.
    // Если курс привязан к существующей теме — он не должен сниматься, даже если
    // его parentId в API не импортировался как направление.
    foreach ($coursesForUnpublishUuids as $courseId) {
      if (!isset($coursesNodesByUuids[$courseId])) {
        continue;
      }
      $node = $coursesNodesByUuids[$courseId];
      $themeEntity = $node->get('field_course_theme')->entity;
      if ($themeEntity) {
        $themeId = $themeEntity->get('field_theme_id')->getString();
        if (!empty($themeId) && isset($allThemesNodesByUuids[$themeId])) {
          unset($coursesForUnpublishUuids[array_search($courseId, $coursesForUnpublishUuids)]);
        }
      }
    }

    $coursesForUnpublishUuids = array_values($coursesForUnpublishUuids);

    // Снимаем с публикации курсы, которых нет в API (не существуют в СДО).
    $unpublishedCount = $this->unpublishCourses($coursesForUnpublishUuids, $coursesNodesByUuids);

    // === Обрабатываем курсы в игнорируемых направлениях ===
    $unpublishIgnoredCoursesCount = 0;
    $publishIgnoredCoursesCount = 0;
    if ($settings->get('unpublish_ignored_courses')) {
      $unpublishIgnoredCoursesCount = $this->unpublishCoursesByIgnoredThemes($coursesInIgnoredThemes, $coursesNodesByUuids);
      $publishIgnoredCoursesCount = $this->publishCoursesNotInIgnoredThemes($coursesInIgnoredThemes, $coursesNodesByUuids);
    }

    $this->log("Обновлено курсов: {$updatedCount}");
    $this->log("Снято с публикации курсов: {$unpublishedCount}");
    if ($settings->get('unpublish_ignored_courses')) {
      if ($unpublishIgnoredCoursesCount > 0) {
        $this->log("Снято с публикации курсов (направление в исключениях): {$unpublishIgnoredCoursesCount}");
      }
      if ($publishIgnoredCoursesCount > 0) {
        $this->log("Возобновлено курсов (направление больше не в исключениях): {$publishIgnoredCoursesCount}");
      }
    }

    return $coursesNodesByUuids;
  }

  /**
   * Возвращает UUID курсов, чьи направления (на любой глубине) стоят в исключениях.
   */
  private function getCoursesInIgnoredThemes(array $apiCourses, array $ignoredThemesIds): array
  {
    $collectDescendantIds = function (string $parentId) use (&$collectDescendantIds, $apiCourses): array {
      $ids = [];
      foreach ($apiCourses as $apiCourse) {
        if ($apiCourse->parentId === $parentId) {
          $ids[] = $apiCourse->id;
          $ids = array_merge($ids, $collectDescendantIds($apiCourse->id));
        }
      }
      return $ids;
    };

    // Собираем все UUID, которые нужно игнорировать:
    // само игнорируемое направление + все его потомки.
    $allIgnoredThemeIds = [];
    foreach ($ignoredThemesIds as $ignoredId) {
      $allIgnoredThemeIds[] = $ignoredId;
      $allIgnoredThemeIds = array_merge($allIgnoredThemeIds, $collectDescendantIds($ignoredId));
    }
    // Переворачиваем для быстрого поиска по ключу (isset).
    $allIgnoredThemeIds = array_flip($allIgnoredThemeIds);

    $courses = [];
    foreach ($apiCourses as $apiCourse) {
      if (isset($allIgnoredThemeIds[$apiCourse->parentId])) {
        $courses[] = $apiCourse->id;
      }
    }

    return $courses;
  }

  /**
   * Фильтрует курсы API, пригодные к импорту.
   *
   * Пропускает:
   * - направления (их id совпадает с id импортированной темы)
   * - курсы без импортированного направления (parentId отсутствует в $importedThemesNodesByUuids)
   * - курсы, чей id стоит в исключениях
   * - курсы без уроков
   */
  private function getSuitableApiCourses(array $apiCourses, array $allThemesNodesByUuids, array $importedThemesNodesByUuids, array $ignoredThemesIds): array
  {
    $suitable = [];
    foreach ($apiCourses as $apiCourse) {
      // Это не курс, а направление — пропускаем.
      if (isset($allThemesNodesByUuids[$apiCourse->id])) {
        continue;
      }
      // Направление курса не импортировано — курс не импортируем.
      if (!isset($importedThemesNodesByUuids[$apiCourse->parentId])) {
        continue;
      }
      // Курс стоит в исключениях — пропускаем.
      if (in_array($apiCourse->id, $ignoredThemesIds)) {
        continue;
      }
      // Курс без уроков — пропускаем.
      if ($apiCourse->lessonsCount <= 0) {
        continue;
      }
      $suitable[] = $apiCourse;
    }

    return $suitable;
  }

  /**
   * Обновляет направление курса, если parentId изменился.
   *
   * Если поле field_course_fix_theme включено — направление не меняется,
   * даже если в СДО курс перенесён в другое направление.
   */
  private function updateCourseTheme(ApiCourse $apiCourse, ApiCourse $previousApiCourse, Node $courseNode, array $importedThemesNodesByUuids, array &$changedFields): void
  {
    if ($apiCourse->parentId != $previousApiCourse->parentId) {
      $fixTheme = (bool)$courseNode->get('field_course_fix_theme')->getString();
      if (!$fixTheme) {
        $changedFields[] = 'field_course_theme';
        $courseNode->set('field_course_theme', [
          'entity' => $importedThemesNodesByUuids[$apiCourse->parentId],
        ]);
      }
    }
  }

  /**
   * Обновляет тип обучения курса, если он появился, исчез или изменился.
   *
   * Работает только если включена настройка import_types.
   */
  private function updateCourseType(ApiCourse $apiCourse, ApiCourse $previousApiCourse, Node $courseNode, array $typesNodesByIds, array &$changedFields): void
  {
    if (!$this->needImportTypes()) {
      return;
    }

    $typeAppeared = empty($previousApiCourse->type->id) && !empty($apiCourse->type->id);
    $typeFaded = !empty($previousApiCourse->type->id) && empty($apiCourse->type->id);
    $typesChanged = !empty($previousApiCourse->type->id) && !empty($apiCourse->type->id) && ($previousApiCourse->type->id != $apiCourse->type->id);

    if ($typeAppeared || $typeFaded || $typesChanged) {
      $changedFields[] = 'field_course_training_type';
      if ($typeFaded) {
        $courseNode->set('field_course_training_type', null);
      } else {
        $courseNode->set('field_course_training_type', [
          'entity' => $typesNodesByIds[$apiCourse->type->id],
        ]);
      }
    }
  }

  /**
   * Обновляет поля курса (название, описание, цена, часы, план).
   *
   * Название, описание и цена обновляются только если включены соответствующие настройки.
   * Цена не обновляется, если в СДО она отсутствует (null).
   * Часы и учебный план обновляются всегда.
   * План сравнивается как десериализованный массив, а не строка.
   */
  private function updateCourseFields(ApiCourse $apiCourse, ApiCourse $previousApiCourse, Node $courseNode, string $shortTitle, int|float $price, $apiPrice, array &$changedFields): void
  {
    $settings = $this->getSettings();

    if ($settings->get('update_courses_titles') && ($apiCourse->title != $previousApiCourse->title)) {
      $changedFields[] = 'title';
      $courseNode->set('title', $shortTitle);
      $courseNode->set('field_course_title', $apiCourse->title);
    }

    if ($settings->get('update_courses_descriptions') && ($apiCourse->description != $previousApiCourse->description)) {
      $changedFields[] = 'field_course_description';
      $courseNode->set('field_course_description', [
        'value' => $apiCourse->description,
        'format' => 'full_html',
      ]);
    }

    if ($settings->get('update_courses_prices') && !is_null($apiPrice) && ($price != $previousApiCourse->price)) {
      $changedFields[] = 'field_course_price';
      $courseNode->set('field_course_price', ['value' => $apiCourse->price]);
    }

    if ($apiCourse->hours != $previousApiCourse->hours) {
      $changedFields[] = 'field_course_hours';
      $courseNode->set('field_course_hours', ['value' => $apiCourse->hours]);
    }

    $apiPlan = $this->getApiPlan($apiCourse);
    $rawPlan = $courseNode->get('field_course_plan')->getValue();
    $previousPlan = null;
    if (!empty($rawPlan) && isset($rawPlan[0]['value'])) {
      $previousPlan = unserialize($rawPlan[0]['value']);
    }
    if ($apiPlan != $previousPlan) {
      $changedFields[] = 'field_course_plan';
      $serializedPlan = $apiPlan ? serialize($apiPlan) : null;
      $courseNode->set('field_course_plan', $serializedPlan ? ['value' => $serializedPlan] : null);
    }
  }

  /**
   * Снимает с публикации курсы по списку UUID. Возвращает количество снятых.
   */
  private function unpublishCourses(array $courseIds, array $coursesNodesByUuids): int
  {
    $count = 0;
    foreach ($courseIds as $courseId) {
      $courseNode = $coursesNodesByUuids[$courseId];

      if ($courseNode->isPublished()) {
        $lockedValue = $courseNode->get('field_course_locked')->getValue();
        $isCourseLocked = !empty($lockedValue) && $lockedValue[0]['value'] == 1;
        if (!$isCourseLocked) {
          $count++;
          $courseNode->setUnpublished();
          $courseNode->save();

          $this->status("Курс <a href=\"/node/{$courseNode->id()}/edit\" target=\"_blank\">{$courseNode->get('field_course_title')->getString()}</a> (<a href=\"{$this->getApiCourseUrlById($courseId)}\" target=\"_blank\">СДО</a>) снят с публикации.");
        } else {
          $this->status("Курс <a href=\"/node/{$courseNode->id()}/edit\" target=\"_blank\">{$courseNode->get('field_course_title')->getString()}</a> (<a href=\"{$this->getApiCourseUrlById($courseId)}\" target=\"_blank\">СДО</a>) не снят с публикации т.к. защищён.");
        }
      }
    }

    return $count;
  }

  /**
   * Снимает с публикации курсы, чьи направления стоят в исключениях. Возвращает количество снятых.
   */
  private function unpublishCoursesByIgnoredThemes(array $courseIds, array $coursesNodesByUuids): int
  {
    $count = 0;
    foreach ($courseIds as $courseId) {
      if (!isset($coursesNodesByUuids[$courseId])) {
        continue;
      }
      $courseNode = $coursesNodesByUuids[$courseId];

      if ($courseNode->isPublished()) {
        $lockedValue = $courseNode->get('field_course_locked')->getValue();
        $isCourseLocked = !empty($lockedValue) && $lockedValue[0]['value'] == 1;
        if (!$isCourseLocked) {
          $count++;
          $courseNode->setUnpublished();
          $courseNode->save();

          $this->status("Курс <a href=\"/node/{$courseNode->id()}/edit\" target=\"_blank\">{$courseNode->get('field_course_title')->getString()}</a> (<a href=\"{$this->getApiCourseUrlById($courseId)}\" target=\"_blank\">СДО</a>) снят с публикации (направление в исключениях).");
        } else {
          $this->status("Курс <a href=\"/node/{$courseNode->id()}/edit\" target=\"_blank\">{$courseNode->get('field_course_title')->getString()}</a> (<a href=\"{$this->getApiCourseUrlById($courseId)}\" target=\"_blank\">СДО</a>) не снят с публикации т.к. защищён.");
        }
      }
    }

    return $count;
  }

  /**
   * Восстанавливает публикацию курсов, которые больше не в игнорируемых направлениях.
   */
  private function publishCoursesNotInIgnoredThemes(array $ignoredCourseIds, array $coursesNodesByUuids): int
  {
    $ignoredSet = array_flip($ignoredCourseIds);
    $count = 0;

    foreach ($coursesNodesByUuids as $courseId => $courseNode) {
      if (isset($ignoredSet[$courseId])) {
        continue;
      }
      if ($courseNode->isPublished()) {
        continue;
      }
      $isCourseLocked = (bool)$courseNode->get('field_course_locked')->getValue();
      if ($isCourseLocked) {
        continue;
      }
      $count++;
      $courseNode->setPublished();
      $courseNode->save();

      $this->status("Курс <a href=\"/node/{$courseNode->id()}/edit\" target=\"_blank\">{$courseNode->get('field_course_title')->getString()}</a> (<a href=\"{$this->getApiCourseUrlById($courseId)}\" target=\"_blank\">СДО</a>) возобновлён (направление больше не в исключениях).");
    }

    return $count;
  }

  /**
   * Выводит статусное сообщение пользователю.
   */
  private function status($messageText)
  {
    $message = Markup::create($messageText);
    $messanger = Drupal::messenger();
    $messanger->addMessage($message, $messanger::TYPE_STATUS);
  }

  /**
   * Выводит предупреждение пользователю.
   */
  private function warning($messageText)
  {
    $message = Markup::create($messageText);
    $messanger = Drupal::messenger();
    $messanger->addMessage($message, $messanger::TYPE_WARNING);
  }

  /**
   * Записывает сообщение в лог и выводит пользователю.
   */
  private function log($message)
  {
    $this->status($message);
    Drupal::logger('uchi_pro')->info($message);
  }

  /**
   * Возвращает URL курса в СДО по объекту ApiCourse.
   */
  private function getApiCourseUrl(ApiCourse $apiCourse)
  {
    return $this->getApiCourseUrlById($apiCourse->id);
  }

  /**
   * Возвращает URL курса в СДО по UUID.
   */
  private function getApiCourseUrlById(string $courseId): string
  {
    $settings = $this->getSettings();
    $url = $settings->get('url');

    return "{$url}/courses/{$courseId}";
  }

  /**
   * Создаёт «виртуальный» ApiCourse из данных Drupal-ноды.
   *
   * Используется для сравнения: данные из API vs данные, сохранённые в Drupal.
   *
   * @return ApiCourse
   */
  private function createApiCourseByNode(Node $node): ApiCourse
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
    $apiCourse->hours = (int)$node->get('field_course_hours')->getString() ?? null;

    return $apiCourse;
  }

  /**
   * Собирает учебный план курса из API как плоский массив.
   *
   * Каждый элемент: ['title' => ..., 'hours' => ..., 'type' => ...].
   * Возвращает null, если план пустой.
   *
   * @return array|null
   */
  private function getApiPlan(ApiCourse $apiCourse): ?array
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

    return $plan ?: null;
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
