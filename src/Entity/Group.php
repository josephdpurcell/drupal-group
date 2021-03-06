<?php
/**
 * @file
 * Contains \Drupal\group\Entity\Group.
 */

namespace Drupal\group\Entity;

use Drupal\group\GroupMembership;
use Drupal\user\UserInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Defines the Group entity.
 *
 * @ingroup group
 *
 * @ContentEntityType(
 *   id = "group",
 *   label = @Translation("Group entity"),
 *   bundle_label = @Translation("Group type"),
 *   handlers = {
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "views_data" = "Drupal\group\Entity\Views\GroupViewsData",
 *     "list_builder" = "Drupal\group\Entity\Controller\GroupListBuilder",
 *     "route_provider" = {
 *       "html" = "Drupal\group\Entity\Routing\GroupRouteProvider",
 *     },
 *     "form" = {
 *       "add" = "Drupal\group\Entity\Form\GroupForm",
 *       "edit" = "Drupal\group\Entity\Form\GroupForm",
 *       "delete" = "Drupal\group\Entity\Form\GroupDeleteForm",
 *     },
 *     "access" = "Drupal\group\Entity\Access\GroupAccessControlHandler",
 *   },
 *   base_table = "groups",
 *   fieldable = TRUE,
 *   entity_keys = {
 *     "id" = "id",
 *     "bundle" = "type",
 *     "label" = "label",
 *     "langcode" = "langcode",
 *     "uuid" = "uuid",
 *   },
 *   links = {
 *     "canonical" = "/group/{group}",
 *     "edit-form" = "/group/{group}/edit",
 *     "delete-form" = "/group/{group}/delete",
 *     "collection" = "/group/list"
 *   },
 *   bundle_entity_type = "group_type",
 *   field_ui_base_route = "entity.group_type.edit_form",
 *   permission_granularity = "bundle"
 * )
 */
class Group extends ContentEntityBase implements GroupInterface {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime() {
    return $this->get('created')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getChangedTime() {
    return $this->get('changed')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getOwner() {
    return $this->get('uid')->entity;
  }

  /**
   * {@inheritdoc}
   */
  public function getOwnerId() {
    return $this->get('uid')->target_id;
  }

  /**
   * {@inheritdoc}
   */
  public function setOwnerId($uid) {
    $this->set('uid', $uid);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setOwner(UserInterface $account) {
    $this->set('uid', $account->id());
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getGroupType() {
    return $this->type->entity;
  }

  /**
   * {@inheritdoc}
   */
  public function getContent($content_enabler = NULL, $filters = []) {
    $properties = ['gid' => $this->id()] + $filters;

    // If a plugin ID was provided, set the group content type ID for it.
    if (isset($content_enabler)) {
      /** @var \Drupal\group\Plugin\GroupContentEnablerInterface $plugin */
      $plugin = $this->getGroupType()->getContentPlugin($content_enabler);
      $properties['type'] = $plugin->getContentTypeConfigId();
    }

    return \Drupal::entityTypeManager()->getStorage('group_content')->loadByProperties($properties);
  }

  /**
   * {@inheritdoc}
   */
  public function getContentByEntityId($content_enabler, $id) {
    return $this->getContent($content_enabler, ['entity_id' => $id]);
  }

  /**
   * {@inheritdoc}
   */
  public function getContentEntities($content_enabler = NULL, $filters = []) {
    $entities = [];

    foreach ($this->getContent($content_enabler, $filters) as $group_content) {
      $entity = $group_content->getEntity();
      $entities[$entity->id()] = $entity;
    }

    return $entities;
  }

  /**
   * {@inheritdoc}
   */
  public function addMember(AccountInterface $account, $values = []) {
    if (!$this->getMember($account)) {
      $plugin = $this->getGroupType()->getContentPlugin('group_membership');
      $group_content = GroupContent::create([
          'type' => $plugin->getContentTypeConfigId(),
          'gid' => $this->id(),
          'entity_id' => $account->id(),
        ] + $values);
      $group_content->save();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getMember(AccountInterface $account) {
    return GroupMembership::load($this, $account);
  }

  /**
   * {@inheritdoc}
   */
  public function getMembers($roles = NULL) {
    return GroupMembership::loadByGroup($this, $roles);
  }

  /**
   * {@inheritdoc}
   */
  public function hasPermission($permission, AccountInterface $account) {
    // If the account can bypass all group access, return immediately.
    if ($account->hasPermission('bypass group access')) {
      return TRUE;
    }

    // Before anything else, check if the user can administer the group.
    if ($permission != 'administer group' && $this->hasPermission('administer group', $account)) {
      return TRUE;
    }

    // If the user has a membership, check for the permission there.
    if ($group_membership = $this->getMember($account)) {
      return $group_membership->hasPermission($permission);
    }

    // Otherwise, check the outsider or anonymous role.
    return $account->isAuthenticated()
      ? GroupRole::load($this->bundle() . '-outsider')->hasPermission($permission)
      : GroupRole::load($this->bundle() . '-anonymous')->hasPermission($permission);
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields['id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Group ID'))
      ->setDescription(t('The ID of the Group entity.'))
      ->setReadOnly(TRUE)
      ->setSetting('unsigned', TRUE);

    $fields['uuid'] = BaseFieldDefinition::create('uuid')
      ->setLabel(t('UUID'))
      ->setDescription(t('The UUID of the Group entity.'))
      ->setReadOnly(TRUE);

    $fields['type'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Type'))
      ->setDescription(t('The group type.'))
      ->setSetting('target_type', 'group_type')
      ->setReadOnly(TRUE);

    $fields['langcode'] = BaseFieldDefinition::create('language')
      ->setLabel(t('Language'))
      ->setDescription(t('The group language code.'))
      ->setTranslatable(TRUE)
      ->setDisplayOptions('view', ['type' => 'hidden'])
      ->setDisplayOptions('form', [
        'type' => 'language_select',
        'weight' => 2,
      ]);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Title'))
      ->setRequired(TRUE)
      ->setTranslatable(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => -5,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Group creator'))
      ->setDescription(t('The username of the group creator.'))
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setDefaultValueCallback('Drupal\group\Entity\Group::getCurrentUserId')
      ->setTranslatable(TRUE)
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created on'))
      ->setDescription(t('The time that the group was created.'))
      ->setTranslatable(TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed on'))
      ->setDescription(t('The time that the group was last edited.'))
      ->setTranslatable(TRUE);

    return $fields;
  }

  /**
   * Default value callback for 'uid' base field definition.
   *
   * @see ::baseFieldDefinitions()
   *
   * @return array
   *   An array of default values.
   */
  public static function getCurrentUserId() {
    return [\Drupal::currentUser()->id()];
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE) {
    parent::postSave($storage, $update);

    // If a new group is created, add the creator as a member by default.
    // @todo Add creator roles by passing in a second parameter like this:
    // ['group_roles' => ['foo', 'bar']].
    if ($update === FALSE) {
      $this->addMember($this->getOwner());
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function postDelete(EntityStorageInterface $storage, array $entities) {
    // Remove all group content from these groups as well.
    foreach ($entities as $group) {
      foreach ($group->getContent() as $group_content) {
        $group_content->delete();
      }
    }
  }

}
