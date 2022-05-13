<?php

namespace Drupal\terms_display\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Routing\ResettableStackedRouteMatchInterface;
use Drupal\Core\Database\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\wbumenudomain\WbumenudomainMenuItemDecorating;

/**
 * Provides an example block.
 *
 * @Block(
 *   id = "terms_display_termsvacao",
 *   admin_label = @Translation(" Terms display vocabulaire "),
 *   category = @Translation(" terms display ")
 * )
 */
class TermsDisplayTermsBlock extends BlockBase implements ContainerFactoryPluginInterface {
  const SHOW_COUNT_NONE = '0';
  const SHOW_COUNT_NODE = '1';
  const SHOW_COUNT_COMMERCE_PRODUCT = '2';
  
  /**
   * Entity mapping.
   *
   * @var string[]
   */
  protected $entitiesMap = [
    self::SHOW_COUNT_NONE => '0',
    self::SHOW_COUNT_NODE => 'node',
    self::SHOW_COUNT_COMMERCE_PRODUCT => 'commerce_product'
  ];
  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;
  
  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;
  
  /**
   * The current route match service.
   *
   * @var \Drupal\Core\Routing\CurrentRouteMatch
   */
  protected $currentRouteMatch;
  
  /**
   * The the current primary database.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;
  
  /**
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;
  protected $domain;
  
  /**
   *
   * @param array $configuration
   * @param string $plugin_id
   * @param mixed $plugin_definition
   * @param EntityTypeManagerInterface $entity_type_manager
   * @param LanguageManagerInterface $language_manager
   * @param ResettableStackedRouteMatchInterface $current_route_match
   * @param Connection $database
   * @param RequestStack $RequestStack
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityFieldManagerInterface $entity_field_manager, EntityTypeManagerInterface $entity_type_manager, LanguageManagerInterface $language_manager, ResettableStackedRouteMatchInterface $current_route_match, Connection $database, EntityTypeBundleInfoInterface $entity_type_bundle_info, RequestStack $RequestStack) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityFieldManager = $entity_field_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->languageManager = $language_manager;
    $this->currentRouteMatch = $current_route_match;
    $this->database = $database;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->request = $RequestStack->getCurrentRequest();
    $this->domain = WbumenudomainMenuItemDecorating::getCurrentActiveDomaineByUrl();
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('entity_field.manager'), $container->get('entity_type.manager'), $container->get('language_manager'), $container->get('current_route_match'), $container->get('database'), $container->get('entity_type.bundle.info'), $container->get('request_stack'));
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function build() {
    $vocabulary = $this->configuration['vocabulary'];
    $base_term = !empty($this->configuration['base_term']) ? $this->configuration['base_term'] : 0;
    $max_depth = !empty($this->configuration['max_depth']) ? $this->configuration['max_depth'] : 0;
    $min_depth = !empty($this->configuration['min_depth']) ? $this->configuration['min_depth'] : 0;
    $display_mode = !empty($this->configuration['display_mode']) ? $this->configuration['display_mode'] : 'full';
    $show_count = $this->configuration['show_count'];
    $referencing_field = $this->configuration['referencing_field'];
    
    /**
     *
     * @var \Drupal\taxonomy\TermStorage $EntityStorage
     */
    $EntityStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    $vocabulary_tree = $EntityStorage->loadTree($vocabulary, $base_term, $max_depth + 1, true);
    $termes = [];
    
    /**
     *
     * @var \Drupal\taxonomy\Entity\Term $term
     */
    foreach ($vocabulary_tree as $term) {
      if (!empty($show_count)) {
        $entitys = $this->getEntityIds($this->entitiesMap[$show_count], $referencing_field, $term->id(), $vocabulary, $this->configuration['calculate_count_recursively']);
        if (!empty($entitys) && empty($termes[$term->id()])) {
          $termes[$term->id()] = $this->renderElment($term, $display_mode, $entitys);
          $this->loadParentTerms($term, $termes, $display_mode, $base_term);
        }
      }
      else {
        $termes[$term->id()] = [
          'tid' => $term->id(),
          'parents' => $term->parents,
          'term' => $this->entityTypeManager->getViewBuilder('taxonomy_term')->view($term, $display_mode),
          'entities' => []
        ];
      }
    }
    if ('specialite_realisation_' == $vocabulary) {
      // dump($termes);
    }
    
    $tree = $this->generateTree($termes, $base_term);
    // dump($tree);
    if ($min_depth)
      $tree = $this->SelectLevel($tree, $min_depth);
    // $tree = [];
    // dump($tree);
    
    
    return [
      '#theme' => 'terms_display',
      '#items' => $tree,
      '#route_tid' => $this->getCurrentRoute()
    ];
  }
  
  private function renderElment(\Drupal\taxonomy\Entity\Term $term, $display_mode = 'full', $entitys = []) {
    return [
      'tid' => $term->id(),
      'parents' => $this->getParentIds($term),
      'term' => $this->entityTypeManager->getViewBuilder('taxonomy_term')->view($term, $display_mode),
      'entities' => $entitys
    ];
  }
  
  private function loadParentTerms(\Drupal\taxonomy\Entity\Term $term, array &$termes, $display_mode, int $parent = 0) {
    $parents = $this->getParentIds($term);
    // dump($term->getName());
    if ($parents['target_id'] != $parent) {
      $termParent = $this->entityTypeManager->getStorage('taxonomy_term')->load($parents['target_id']);
      if (empty($termes[$termParent->id()])) {
        $termes[$termParent->id()] = $this->renderElment($termParent, $display_mode);
        $this->loadParentTerms($termParent, $termes, $display_mode, $parent);
      }
    }
  }
  
  private function getParentIds(\Drupal\taxonomy\Entity\Term $term) {
    return $term->get('parent')->offsetGet(0)->getValue();
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form['basic'] = [
      '#type' => 'details',
      '#title' => $this->t('Basic settings')
    ];
    
    $form['basic']['vocabulary'] = [
      '#title' => $this->t('Use taxonomy terms from this vocabulary to create a menu'),
      '#type' => 'select',
      '#options' => $this->getVocabularyOptions(),
      '#required' => TRUE,
      '#default_value' => $this->configuration['vocabulary']
    ];
    
    $form['basic']['max_depth'] = [
      '#title' => $this->t('Number of sublevels to display'),
      '#type' => 'select',
      '#options' => [
        '0' => '0',
        '1' => '1',
        '2' => '2',
        '3' => '3',
        '4' => '4',
        '5' => '5',
        '6' => '6',
        '7' => '7',
        '8' => '8',
        '9' => '9',
        '10' => '10',
        '100' => $this->t('Unlimited')
      ],
      '#default_value' => $this->configuration['max_depth']
    ];
    
    $form['basic']['min_depth'] = [
      '#title' => $this->t('niveau minimal (level)'),
      '#type' => 'textfield',
      '#size' => 20,
      '#default_value' => $this->configuration['min_depth']
    ];
    
    $form['basic']['display_mode'] = [
      '#title' => $this->t(" Mode d'affichage "),
      '#type' => 'textfield',
      '#size' => 20,
      '#default_value' => $this->configuration['display_mode']
    ];
    
    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t(' Advanced settings ')
    ];
    
    $form['advanced']['base_term'] = [
      '#type' => 'textfield',
      '#title' => $this->t(' Id du terme parent (terme de base) '),
      '#size' => 20,
      '#default_value' => $this->configuration['base_term']
    ];
    $form['advanced']['show_count'] = [
      '#type' => 'radios',
      '#title' => $this->t('Show count of referencing entities'),
      '#options' => [
        0 => $this->t('No'),
        1 => $this->t('Nodes'),
        2 => $this->t('Products')
      ],
      '#default_value' => $this->configuration['show_count']
    ];
    
    if (!empty($this->configuration['show_count']) && $this->configuration['show_count'] == 2)
      $form['advanced']['referencing_field'] = [
        '#type' => 'select',
        '#title' => $this->t('Referencing field product'),
        '#options' => $this->getReferencingFields(),
        '#default_value' => $this->configuration['referencing_field'],
        '#states' => [
          'visible' => [
            ':input[name="settings[advanced][show_count]"]' => [
              'value' => '2'
            ]
          ]
        ]
      ];
    if (!empty($this->configuration['show_count']) && $this->configuration['show_count'] == 1)
      $form['advanced']['referencing_field'] = [
        '#type' => 'select',
        '#title' => $this->t('Referencing field Node'),
        '#options' => $this->getReferencingFields('node'),
        '#default_value' => $this->configuration['referencing_field'],
        '#states' => [
          'visible' => [
            ':input[name="settings[advanced][show_count]"]' => [
              'value' => '1'
            ]
          ]
        ]
      ];
    return $form;
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->configuration['vocabulary'] = $form_state->getValue([
      'basic',
      'vocabulary'
    ]);
    
    $this->configuration['max_depth'] = $form_state->getValue([
      'basic',
      'max_depth'
    ]);
    
    $this->configuration['min_depth'] = $form_state->getValue([
      'basic',
      'min_depth'
    ]);
    
    $this->configuration['display_mode'] = $form_state->getValue([
      'basic',
      'display_mode'
    ]);
    
    $this->configuration['base_term'] = $form_state->getValue([
      'advanced',
      'base_term'
    ]);
    
    $this->configuration['show_count'] = $form_state->getValue([
      'advanced',
      'show_count'
    ]);
    
    $this->configuration['referencing_field'] = $form_state->getValue([
      'advanced',
      'referencing_field'
    ]);
    
    $this->configuration['calculate_count_recursively'] = $form_state->getValue([
      'advanced',
      'calculate_count_recursively'
    ]);
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'vocabulary' => '',
      'max_depth' => 100,
      'dynamic_block_title' => FALSE,
      // 'collapsible' => FALSE,
      // 'stay_open' => FALSE,
      // 'interactive_parent' => FALSE,
      // 'hide_block' => FALSE,
      // 'use_image_style' => FALSE,
      // 'image_height' => 16,
      // 'image_width' => 16,
      // 'image_style' => '',
      // 'max_age' => 0,
      // 'base_term' => '',
      // 'dynamic_base_term' => FALSE,
      'show_count' => '0',
      'referencing_field' => '_none',
      'calculate_count_recursively' => FALSE
    ];
  }
  
  /**
   * Generates menu tree.
   */
  private function generateTree(array $array, $parent = 0) {
    $tree = [];
    foreach ($array as $item) {
      if (reset($item['parents']) == $parent) {
        $item['subitem'] = isset($item['subitem']) ? $item['subitem'] : $this->generateTree($array, $item['tid']);
        $tree[] = $item;
      }
    }
    return $tree;
  }
  
  /**
   * Generates vocabulary select options.
   */
  private function getVocabularyOptions() {
    $options = [];
    $vocabularies = \Drupal::entityQuery('taxonomy_vocabulary')->execute();
    foreach ($vocabularies as $vocabulary) {
      $options[$vocabulary] = $this->t('@vocabulary', [
        '@vocabulary' => ucfirst($vocabulary)
      ]);
    }
    return $options;
  }
  
  /**
   * Gets current route.
   */
  private function getCurrentRoute() {
    if ($term_id = $this->currentRouteMatch->getRawParameter('taxonomy_term')) {
      return $term_id;
    }
    return NULL;
  }
  
  /**
   *
   * @param array $tree
   * @param int $number
   */
  private function SelectLevel(array $tree, int $level) {
    $newtree = [];
    for ($i = 0; $i < $level; $i++) {
      $newtree = [];
      foreach ($tree as $group) {
        if (!empty($group['subitem'])) {
          $newtree[]['subitem'] = $group['subitem'];
        }
      }
      $tree = $newtree;
    }
    
    return $newtree;
  }
  
  /**
   * Gets all entities referencing the given term.
   */
  private function getEntityIds($entity_type_id, $field_name, $tid, $vocabulary, $calculate_count_recursively) {
    if (!$calculate_count_recursively) {
      return $this->getEntityIdsForTerm($entity_type_id, $field_name, $tid);
    }
    else {
      $entity_ids = $this->getEntityIdsForTerm($entity_type_id, $field_name, $tid);
      
      $child_tids = $this->entityTypeManager->getStorage('taxonomy_term')->loadTree($vocabulary, $tid);
      
      foreach ($child_tids as $child_tid) {
        $entity_ids = array_merge($entity_ids, $this->getEntityIdsForTerm($entity_type_id, $field_name, $child_tid->tid));
      }
      
      return $entity_ids;
    }
  }
  
  /**
   * Gets entities referencing the given term.
   */
  private function getEntityIdsForTerm($entity_type_id, $field_name, $tid) {
    if (empty($field_name)) {
      return [];
    }
    
    if ($entity_type_id == 'node') {
      $query = ' select DISTINCT cpf.entity_id from `node__' . $field_name . '` as cpf ';
      $query .= "
        INNER JOIN `node__field_domain_access` AS fd ON ( fd.`entity_id` = cpf.`entity_id` )";
      $query .= " where cpf." . $field_name . "_target_id = " . $tid . " and fd.`field_domain_access_target_id`='" . $this->domain . "'";
      $results = $this->database->query($query)->fetchCol();
      return $results;
      // return $this->database->select('taxonomy_index', 'ta')->fields('ta', [
      // 'nid'
      // ])->distinct(TRUE)->condition('tid', $tid)->execute()->fetchCol();
    }
    else {
      $query = ' select DISTINCT entity_id from `commerce_product__' . $field_name . '` as cpf ';
      $query .= " 
        INNER JOIN `commerce_product_field_data` AS fd ON ( fd.`product_id` = cpf.`entity_id` )";
      $query .= " where " . $field_name . "_target_id = " . $tid . " and fd.`field_domain_access`='" . $this->domain . "'";
      $results = $this->database->query($query)->fetchCol();
      return $results;
      // return $this->database->select('commerce_product__' . $field_name,
      // 'cp')->fields('cp', [
      // 'entity_id'
      // ])->distinct(TRUE)->condition($field_name . '_target_id',
      // $tid)->execute()->fetchCol();
    }
  }
  
  /**
   * Gets taxonomy term fields from commerce product entity.
   *
   * @return array An array of taxonomy term fields.
   */
  private function getReferencingFields($type = "commerce_product") {
    $referencing_fields = [];
    $referencing_fields['_none'] = $this->t('- None -');
    $bundles = $this->entityTypeBundleInfo->getBundleInfo($type);
    foreach ($bundles as $bundle => $data) {
      $fields = $this->entityFieldManager->getFieldDefinitions($type, $bundle);
      /**
       *
       * @var \Drupal\Core\Field\FieldDefinitionInterface $field
       */
      foreach ($fields as $field) {
        if ($field->getType() == 'entity_reference' && $field->getSetting('target_type') == 'taxonomy_term') {
          $referencing_fields[$field->getName()] = $field->getLabel();
        }
      }
    }
    return $referencing_fields;
  }
  
}