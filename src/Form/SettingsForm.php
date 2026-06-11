<?php

namespace Drupal\uchi_pro\Form;

use Drupal;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\uchi_pro\Exception\BadRoleException;
use Drupal\uchi_pro\Service\ImportCoursesService;
use Exception;
use UchiPro\ApiClient;

class SettingsForm extends ConfigFormBase {

  const SETTINGS = 'uchi_pro.settings';

  public function getFormId() {
    return 'uchi_pro_admin_settings';
  }

  protected function getEditableConfigNames() {
    return [
      static::SETTINGS,
    ];
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config(static::SETTINGS);

    $form['identity'] = [
      '#type' => 'fieldset',
      '#title' => 'Доступ к СДО',
    ];

    $form['identity']['url'] = [
      '#type' => 'textfield',
      '#title' => 'URL СДО',
      '#default_value' => $config->get('url'),
    ];

    $form['identity']['access_token'] = [
      '#type' => 'textfield',
      '#title' => 'Токен',
      '#default_value' => $config->get('access_token'),
    ];

    $form['import_courses'] = [
      '#type' => 'fieldset',
      '#title' => 'Импорт курсов',
    ];

    $form['import_courses']['ignored_themes_ids'] = [
      '#type' => 'textarea',
      '#title' => 'Идентификаторы направлений (UUID), курсы по которым не должны импортироваться на сайт',
      '#rows' => 6,
      '#default_value' => $config->get('ignored_themes_ids'),
      '#description' => 'По одному идентификатору направления вида 00000000-0000-0000-C000-000000000000 в строку.',
    ];

    $form['import_courses']['publish_courses_on_import'] = [
      '#type' => 'checkbox',
      '#title' => 'Публиковать курсы при импорте',
      '#default_value' => $config->get('publish_courses_on_import'),
    ];

    $form['import_courses']['update_courses_titles'] = [
      '#type' => 'checkbox',
      '#title' => 'Обновлять названия курсов при импорте',
      '#default_value' => $config->get('update_courses_titles'),
    ];

    $form['import_courses']['update_courses_descriptions'] = [
      '#type' => 'checkbox',
      '#title' => 'Обновлять описания курсов при импорте',
      '#default_value' => $config->get('update_courses_descriptions'),
    ];

    $form['import_courses']['update_courses_prices'] = [
      '#type' => 'checkbox',
      '#title' => 'Обновлять цены курсов при импорте',
      '#default_value' => $config->get('update_courses_prices'),
    ];

    $form['import_courses']['import_types'] = [
      '#type' => 'checkbox',
      '#title' => 'Импортировать типы обучения',
      '#default_value' => $config->get('import_types'),
    ];

    $form['import_courses']['use_cron'] = [
      '#type' => 'checkbox',
      '#title' => 'Запускать импорт курсов по крону',
      '#default_value' => $config->get('use_cron'),
    ];

    $form['import_courses']['start_import'] = [
      '#type' => 'checkbox',
      '#title' => 'Запустить импорт после сохранения настроек',
      '#default_value' => true,
    ];

    $moduleHandler = Drupal::service('module_handler');
    if ($moduleHandler->moduleExists('webform') && $config->get('leads_available')) {
      $form['leads'] = [
        '#type' => 'fieldset',
        '#title' => 'Создание лидов',
      ];

      $webforms = Drupal::entityTypeManager()->getStorage('webform')->loadMultiple(null);
      $webforms_options = [];
      foreach ($webforms as $webform) {
        $label = $webform->toLink(NULL, 'canonical', ['attributes' => ['target' => '_blank']])->toString();
        $fields = $this->getWebformFields($webform);
        if (!empty($fields)) {
          $label .= ' (используемые поля: ' . implode(', ', array_map(function ($field) { return "$field->title ({$field->id})"; }, $fields)) . ')';
        } else {
          $label .= ' (нет подходящих полей)';
        }
        $webforms_options[$webform->id()] = $label;
      }
      $form['leads']['leads_webforms'] = [
        '#type' => 'checkboxes',
        '#title' => 'Создавать лиды с форм',
        '#options' => $webforms_options,
        '#default_value' => $config->get('leads_webforms'),
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  private function getWebformFields($webform)
  {
    $fields = [];

    $elements = $webform->getElementsDecodedAndFlattened();

    if (isset($elements['name']) && $elements['name']['#type'] === 'textfield') {
      $fields[] = (object)['id' => 'name', 'title' => 'имя'];
    }

    if (isset($elements['email']) && $elements['email']['#type'] === 'email') {
      $fields[] = (object)['id' => 'email', 'title' => 'e-mail'];
    }

    if (isset($elements['phone']) && $elements['phone']['#type'] === 'tel') {
      $fields[] = (object)['id' => 'phone', 'title' => 'телефон'];
    }

    if (isset($elements['course']) && !empty($elements['course']['#selection_settings']['target_bundles']['course'])) {
      $fields[] = (object)['id' => 'course', 'title' => 'курс'];
    }

    return $fields;
  }

  public function validateForm(array &$form, FormStateInterface $form_state)
  {
    parent::validateForm($form, $form_state);

    $url = $form_state->getValue('url');
    if (empty($url)) {
      return;
    }

    try {
      if (strpos($url, 'http') !== 0) {
        $url = "http://{$url}";
      }
      $url = ApiClient::prepareUrl($url);
    } catch (Exception $e) {
      $form_state->setErrorByName('url', 'Невалидный URL.');
      return;
    }
    $form_state->setValue('url', $url);

    $accessToken = $form_state->getValue('access_token');
    if (empty($accessToken)) {
      $startImport = $form_state->getValue('start_import');
      if ($startImport) {
        $form_state->setErrorByName('url', Markup::create("Для запуска импорта после сохранения настроек укажите токен для доступа менеджера со страницы <a href=\"{$url}/vendor/properties#other\" target=\"_blank\">настроек вендора</a>."));
      }
      return;
    }

    try {
      uchi_pro_check_access_token($url, $accessToken);
    } catch (BadRoleException $e) {
      $form_state->setErrorByName('url', Markup::create("Укажите актуальный токен для доступа менеджера со страницы <a href=\"{$url}/vendor/properties#other\" target=\"_blank\">настроек вендора</a>."));
    } catch (Exception $e) {
      watchdog_exception('error', $e);
      $form_state->setErrorByName('url', 'Не удалось подключиться к СДО.');
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $url = $form_state->getValue('url');
    $accessToken = $form_state->getValue('access_token');
    $ignoredThemesIds = $form_state->getValue('ignored_themes_ids');
    $publishCoursesOnImport = $form_state->getValue('publish_courses_on_import');
    $updateCoursesTitles = $form_state->getValue('update_courses_titles');
    $updateCoursesDescriptions = $form_state->getValue('update_courses_descriptions');
    $updateCoursesPrices = $form_state->getValue('update_courses_prices');
    $importTypes = $form_state->getValue('import_types');
    $useCron = $form_state->getValue('use_cron');
    $leadsWebforms = $form_state->hasValue('leads_webforms')
      ? array_values(array_filter($form_state->getValue('leads_webforms')))
      : [];

    $ignoredThemesIds = implode("\n", array_map(function ($id) {
      return trim($id);
    }, explode("\n", trim($ignoredThemesIds))));

    if (empty($accessToken)) {
      $useCron = 0;
    }

    $leadsAvailable = false;
    if (!empty($accessToken)) {
      $leadsAvailable = _uchi_pro_leads_available($url, $accessToken);
    }

    $config = $this->configFactory->getEditable(static::SETTINGS);
    $config->set('url', $url);
    $config->set('access_token', $accessToken);
    $config->set('ignored_themes_ids', $ignoredThemesIds);
    $config->set('publish_courses_on_import', $publishCoursesOnImport);
    $config->set('update_courses_titles', $updateCoursesTitles);
    $config->set('update_courses_descriptions', $updateCoursesDescriptions);
    $config->set('update_courses_prices', $updateCoursesPrices);
    $config->set('import_types', $importTypes);
    $config->set('use_cron', $useCron);
    $config->set('leads_available', $leadsAvailable);
    $config->set('leads_webforms', $leadsWebforms);
    $config->save();

    parent::submitForm($form, $form_state);

    $startImport = $form_state->getValue('start_import');
    if ($startImport) {
      try {
        $importCoursesService = new ImportCoursesService();
        $importCoursesService->importCourses();
      } catch (Exception $e) {
        Drupal::messenger()->addMessage($e->getMessage(), 'error');
      }
    }
  }
}
