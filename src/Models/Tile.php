<?php

namespace OP\Models;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Group;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\OptionsetField;
use SilverStripe\Forms\ListboxField;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Member;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Versioned\Versioned;
use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Forms\TabSet;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\HTMLEditor\HTMLEditorField;
use OP\Elements\TileElement;

/**
 * 
 */
class Tile extends DataObject {

	use Injectable;

	// enable cascade publishing
	private static $extensions = [
		Versioned::class
	];
	private static $table_name = 'Tile';
	private static $singular_name = "Generic Tile";
	private static $db = [
		'Color' => 'Text', // red, green blue etc.
		'Content' => 'HTMLText', // text in the content field
		'Row' => 'Int',
		'Col' => 'Int',
		'Sort' => 'Int', // calculated by TileField
		'Width' => 'Int',
		'Height' => 'Int',
		//..'Name' => 'Text', // used in one-many relationships
		'Disabled' => 'Boolean',
		'CanViewType' => "Enum('Anyone, LoggedInUsers, OnlyTheseUsers, Inherit', 'Inherit')",
		'CanEditType' => "Enum('LoggedInUsers, OnlyTheseUsers, Inherit', 'Inherit')",
	];
	private static $has_one = [
		'Parent' => TileElement::class
	];
	private static $many_many = [
		'ViewerGroups' => Group::class,
		'EditorGroups' => Group::class,
	];
	private static $defaults = [
		'CanViewType' => 'Inherit',
		'CanEditType' => 'Inherit'
	];
	protected static $maxheight = 2;
	protected static $maxwidth = 2;

	public function __construct($record = null, $isSingleton = false, $model = null) {
		parent::__construct($record, $isSingleton, $model);
	}

	/**
	 * create the field names 
	 * @return \FieldList
	 */
	public function getCMSFields() {
		$fields = FieldList::create();
		$fields->push(new TabSet("Root", $mainTab = new Tab("Main")));
		$fields->addFieldsToTab('Root.Main', CheckboxField::create('Disabled', 'Disabled'));
		$fields->addFieldsToTab('Root.Main', HTMLEditorField::create('Content', 'Content'));

		$fields->addFieldsToTab('Root.Settings', $this->getSettingsFields());

		return $fields;
	}

	/**
	 * how big this tile can grow side ways
	 * @return int
	 */
	public function getMaxWidth() {
		return self::$maxwidth;
	}

	/**
	 * how tall this tile can get
	 * @return int
	 */
	public function getMaxHeight() {
		return self::$maxheight;
	}

	/**
	 * X-Y format of this tile
	 * @return string
	 */
	public function getSize() {
		return $this->Width . '-' . $this->Height;
	}

	/**
	 * Returns fields related to configuration aspects on this record, e.g. access control.
	 * See {@link getCMSFields()} for content-related fields.
	 * 
	 * @return FieldList
	 */
	public function getSettingsFields() {
		$groupsMap = array();
		foreach (Group::get() as $group) {
			// Listboxfield values are escaped, use ASCII char instead of &raquo;
			$groupsMap[$group->ID] = $group->getBreadcrumbs(' > ');
		}
		asort($groupsMap);

		$fields = FieldList::create(array(
					$viewersOptionsField = new OptionsetField(
					"CanViewType", _t('Tile.ACCESSHEADER', "Who can view this tile?")
					),
					$viewerGroupsField = ListboxField::create("ViewerGroups", _t('SiteTree.VIEWERGROUPS', "Viewer Groups"))
					->setSource($groupsMap)
					->setAttribute(
					'data-placeholder', _t('Tile.GroupPlaceholder', 'Click to select group')
					),
					$editorsOptionsField = new OptionsetField(
					"CanEditType", _t('Tile.EDITHEADER', "Who can edit this tile?")
					),
					$editorGroupsField = ListboxField::create("EditorGroups", _t('SiteTree.EDITORGROUPS', "Editor Groups"))
					->setSource($groupsMap)
					->setAttribute(
					'data-placeholder', _t('Tile.GroupPlaceholder', 'Click to select group')
					)
		));

		$viewersOptionsSource = array();
		$viewersOptionsSource["Inherit"] = _t('Tile.INHERIT', "Inherit from parent page");
		$viewersOptionsSource["Anyone"] = _t('Tile.ACCESSANYONE', "Anyone");
		$viewersOptionsSource["LoggedInUsers"] = _t('Tile.ACCESSLOGGEDIN', "Logged-in users");
		$viewersOptionsSource["OnlyTheseUsers"] = _t('Tile.ACCESSONLYTHESE', "Only these people (choose from list)");
		$viewersOptionsField->setSource($viewersOptionsSource);

		$editorsOptionsSource = array();
		$editorsOptionsSource["Inherit"] = _t('Tile.INHERIT', "Inherit from parent page");
		$editorsOptionsSource["LoggedInUsers"] = _t('Tile.EDITANYONE', "Anyone who can log-in to the CMS");
		$editorsOptionsSource["OnlyTheseUsers"] = _t('Tile.EDITONLYTHESE', "Only these people (choose from list)");
		$editorsOptionsField->setSource($editorsOptionsSource);

		if (!Permission::check('SITETREE_GRANT_ACCESS')) {
			$fields->makeFieldReadonly($viewersOptionsField);
			if ($this->CanViewType == 'OnlyTheseUsers') {
				$fields->makeFieldReadonly($viewerGroupsField);
			} else {
				$fields->removeByName('ViewerGroups');
			}

			$fields->makeFieldReadonly($editorsOptionsField);
			if ($this->CanEditType == 'OnlyTheseUsers') {
				$fields->makeFieldReadonly($editorGroupsField);
			} else {
				$fields->removeByName('EditorGroups');
			}
		}

		return $fields;
	}

	/**
	 * render the tile
	 * @return type
	 */
	public function forTemplate() {
		$shortname = (new \ReflectionClass($this))->getShortName();
		return $this->renderWith(array('Tiles/' . $shortname, $shortname));
	}

	/**
	 * Returns CSS friendly name
	 * @return string
	 */
	public function CSSName() {
		$shortname = (new \ReflectionClass($this))->getShortName();
		return strtolower($shortname);
	}

	/**
	 * Validates the tile data object
	 * @return A {@link ValidationResult} object
	 */
	public function validate() {
		$result = parent::validate();

		if ($this->Height > $this::$maxheight) {
			$result->error("Height of $this::\$maxheight exceeded");
		}

		if ($this->Width > $this::$maxwidth) {
			$result->error("Width of $this::\$maxheight exceeded");
		}

		return $result;
	}

	/**
	 * This function should return true if the current user can view this
	 * page. It can be overloaded to customise the security model for an
	 * application.
	 * 
	 * Denies permission if any of the following conditions is TRUE:
	 * - canView() on any extension returns FALSE
	 * - "CanViewType" directive is set to "Inherit" and any parent page return false for canView()
	 * - "CanViewType" directive is set to "LoggedInUsers" and no user is logged in
	 * - "CanViewType" directive is set to "OnlyTheseUsers" and user is not in the given groups
	 *
	 * @uses DataExtension->canView()
	 * @uses ViewerGroups()
	 *
	 * @param Member|int|null $member
	 * @return boolean True if the current user can view this page.
	 */
	public function canView($member = null) {
		if (!$member || !(is_a($member, 'Member')) || is_numeric($member)) {
			$member = Member::currentUserID();
		}

		// admin override
		if ($member && Permission::checkMember($member, array("ADMIN", "SITETREE_VIEW_ALL")))
			return true;

		// Standard mechanism for accepting permission changes from extensions
		$extended = $this->extendedCan('canView', $member);
		if ($extended !== null)
			return $extended;

		// check for empty spec
		if (!$this->CanViewType || $this->CanViewType == 'Anyone')
			return true;

		// check for inherit
		if ($this->CanViewType == 'Inherit') {
			if (!$this->ParentID) {
				return true;
			}
			if (in_array($this->ParentClassName, ClassInfo::getValidSubClasses())) {
				return DataObject::get_by_id('SiteTree', $this->ParentID)->canView();
			} else {
				if (!$this->ParentClassName || !singleton($this->ParentClassName)) {
					return true;
				}
				return singleton($this->ParentClassName)->canView($member);
			}
		}

		// check for any logged-in users
		if ($this->CanViewType == 'LoggedInUsers' && $member) {
			return true;
		}

		// check for specific groups
		if ($member && is_numeric($member))
			$member = DataObject::get_by_id('Member', $member);
		if (
				$this->CanViewType == 'OnlyTheseUsers' && $member && $member->inGroups($this->ViewerGroups())
		)
			return true;

		return false;
	}

	/**
	 * This function should return true if the current user can edit this
	 * page. It can be overloaded to customise the security model for an
	 * application.
	 * 
	 * Denies permission if any of the following conditions is TRUE:
	 * - canEdit() on any extension returns FALSE
	 * - canView() return false
	 * - "CanEditType" directive is set to "Inherit" and any parent page return false for canEdit()
	 * - "CanEditType" directive is set to "LoggedInUsers" and no user is logged in or doesn't have the CMS_Access_CMSMAIN permission code
	 * - "CanEditType" directive is set to "OnlyTheseUsers" and user is not in the given groups
	 * 
	 * @uses canView()
	 * @uses EditorGroups()
	 * @uses DataExtension->canEdit()
	 *
	 * @param Member $member Set to FALSE if you want to explicitly test permissions without a valid user (useful for unit tests)
	 * @return boolean True if the current user can edit this page.
	 */
	public function canEdit($member = null) {
		if ($member instanceof Member) {
			$memberID = $member->ID;
		} else if (is_numeric($member)) {
			$memberID = $member;
		} else {
			$memberID = Member::currentUserID();
		}
		if ($memberID && Permission::checkMember($memberID, array("ADMIN", "SITETREE_EDIT_ALL"))) {
			return true;
		}

		// Standard mechanism for accepting permission changes from extensions
		$extended = $this->extendedCan('canEdit', $memberID);
		if ($extended !== null) {
			return $extended;
		}

		// check for inherit
		if ($this->CanEditType == 'Inherit') {
			if (!$this->ParentID) {
				return true;
			}
			if (in_array($this->ParentClassName, ClassInfo::getValidSubClasses())) {
				return DataObject::get_by_id('SiteTree', $this->ParentID)->canEdit();
			} else {
				if (!$this->ParentClassName || !singleton($this->ParentClassName)) {
					return true;
				}
				return singleton($this->ParentClassName)->canEdit($member);
			}
		}

		// check for any logged-in users
		if ($this->CanEditType == 'LoggedInUsers' && $member) {
			return true;
		}

		// check for specific groups
		if ($member && is_numeric($member)) {
			$member = DataObject::get_by_id('Member', $member);
		}
		if ($this->CanEditType == 'OnlyTheseUsers' && $member && $member->inGroups($this->ViewerGroups())) {
			return true;
		}

		return false;
	}

	/**
	 * get the width of this item (min of 1)
	 * @return int
	 */
	public function getWidth() {
		return max($this->getField('Width'), 1);
	}

	/**
	 * get the height of this item (min of 1)
	 * @return int
	 */
	public function getHeight() {
		return max($this->getField('Height'), 1);
	}

	/**
	 * takes in position x and y, and saves it
	 * @param array $data
	 */
	public function writeRawArray($data) {
		if (isset($data['x'])) {
			$this->Row = (int) $data['x'];
		}
		if (isset($data['y'])) {
			$this->Col = (int) $data['y'];
		}
		if (isset($data['w'])) {
			$this->Width = (int) $data['w'];
		}
		if (isset($data['h'])) {
			$this->Height = (int) $data['h'];
		}
		$this->write();
	}

	/**
	 * takes in position x and y, and saves it
	 * @param array $data
	 */
	public function generateRawArray() {
		return array(
			'i' => $this->ID,
			'n' => $this->singular_name(),
			'x' => (int) $this->Row,
			'y' => (int) $this->Col,
			'w' => (int) $this->getWidth(),
			'h' => (int) $this->getHeight(),
			'maxW' => $this->getMaxWidth(),
			'maxH' => $this->getMaxHeight(),
			'c' => $this->getTileColor(),
			'p' => $this->getPreviewContent(),
			'img' => $this->getPreviewImage(),
			'disabled' => $this->Disabled
		);
	}

	/**
	 * if you specify a background color 
	 * @return string
	 */
	public function getTileColor() {
		return $this->Color ?: 'transparent';
	}

	/**
	 * text to be inside the tile itself
	 * @return string
	 */
	public function getPreviewContent() {
		return DBField::create_field(DBHTMLText::class, $this->Content)->LimitCharacters(150);
	}

	/**
	 * a preview image
	 * @return Image|null
	 */
	public function getPreviewImage() {
		return null;
	}

}
