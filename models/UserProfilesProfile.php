<?php
require_once(PLUGIN_DIR . '/RecordRelations/models/RelatableRecord.php');
class UserProfilesProfile extends RelatableRecord implements Zend_Acl_Resource_Interface {

    public $id;
    public $type_id;
    public $owner_id;
    public $added;
    public $modified;
    public $public;

    protected $namespace = SIOC;
    protected $subject_record_type = 'User';
    protected $object_record_type = 'UserProfilesProfile';
    protected $local_part = 'account_of';
    protected $_isSubject = false;

    /**
     * ElementText records stored in the order they were retrieved from the database.
     *
     * @var array
     */
    protected $_textsByNaturalOrder = array();
    
    /**
     * ElementText records indexed by the element_id.
     *
     * @var array
     */
    protected $_textsByElementId = array();
    
    /**
     * Element records indexed by set name and element name, so it looks like:
     *
     * $elements['Dublin Core']['Title'] = Element instance;
     *
     * @var array
     */
    protected $_elementsBySet = array();
    
    /**
     * Element records indexed by ID.
     *
     * @var array
     */
    protected $_elementsById = array();
    
    /**
     * List of elements that were output on the form.  This can be used to
     * determine the DELETE SQL to use to reset the elements when saving the form.
     *
     * @see ActsAsElementText::_getElementTextsToSaveFromPost()
     * @var array
     */
    protected $_elementsOnForm = array();
    
    /**
     * Set of ElementText records to save when submitting the form.  These will
     * only be saved to the database if they successfully validate.
     *
     * @var array
     */
    protected $_textsToSave = array();
    
    /**
     * Whether the elements and texts have been loaded yet.
     *
     * @var bool
     */
    protected $_recordsAreLoaded = false;
    
    /**
     * Sets of Element records indexed by record type.
     *
     * @var array
     */
    private static $_elementsByRecordType = array();

    public function getProfileType()
    {
        $profileType = $this->getTable('UserProfilesType')->find($this->type_id);
        
        return $profileType;
    }
    
    public function getElements()
    {
        $profileType = $this->getProfileType();
        if($profileType) {
            $elements = $profileType->Elements;
        } else {
            $elements = array();
        }
        return $elements;
    }
    
    protected function _initializeMixins()
    {
        $this->_mixins[] = new Mixin_Owner($this);
        $this->_mixins[] = new Mixin_Timestamp($this);
        $this->_mixins[] = new Mixin_Search($this);
    }    
    
    public function afterSave($args)
    {
        if (!$this->public) {
            $this->setSearchTextPrivate();
        }
        
        $this->saveElementTexts();       
        $this->saveMultiElementValues();
        foreach ($this->getAllElementTexts() as $elementText) {
            $this->addSearchText($elementText->text);
        }        
        
        foreach($this->getAllMultiValues() as $multiValueRecord) {
            $displayValues = $multiValueRecord->getValuesForDisplay();
            foreach($displayValues as $value) {
                $this->addSearchText($value);
            }
        }
        $owner = $this->getOwner();
        $this->setSearchTextTitle($owner->name);
        parent::afterSave($args);
    }
    
    /**
     * Get the database object from the associated record.
     *
     * @return Omeka_Db
     */
    private function _getDb()
    {
        return $this->getDb();
    }
    
    /**
     * Get the class name of the associated record (Item, File, etc.).
     *
     * @return string Type of record
     */
    private function _getRecordType()
    {
        return get_class($this);
    }
    
    /**
     * Load all the ElementText records for the given record (Item, File, etc.).
     * These will be indexed by [element_id].
     *
     * Also load all the Element records and index those by their name and set
     * name.
     *
     * @param boolean $reload Whether or not reload all the data that was
     *                        previously loaded.
     * @return void
     */
    public function loadElementsAndTexts($reload=false)
    {
        if ($this->_recordsAreLoaded and !$reload) {
            return;
        }
    
        $elementTextRecords = $this->_getElementTextRecords();
    
        $this->_textsByNaturalOrder = $elementTextRecords;
        $this->_textsByElementId = $this->_indexTextsByElementId($elementTextRecords);
        $this->_loadElements($reload);
        $this->_recordsAreLoaded = true;
    }
    
    private function _loadElements($reload = false)
    {
        $elements = $this->getElements();
        $recordType = $this->_getRecordType();

        $this->_elementsBySet = $this->_indexElementsBySet($elements);
        $this->_elementsById = $this->_indexElementsById($elements);
    }
    
    /**
     * Retrieve all of the ElementText records for the given record.
     *
     * @return array Set of ElementText records for the record.
     */
    private function _getElementTextRecords()
    {
        return $this->getTable('ElementText')->findByRecord($this);
    }
    
    /**
     * Retrieve all of the Element records for the given record.
     *
     * @return array All Elements that apply to the record's type.
     */
    private function _getElementRecords()
    {
        return $this->getTable('Element')->findByRecordType($this->_getRecordType());
    }
    
    /**
     * Retrieve all of the record's ElementTexts for the given Element.
     *
     * @param Element $element
     * @return array Set of ElementText records.
     */
    public function getElementTextsByRecord($element)
    {
        // Load 'em if we need 'em.
        if (!$this->_recordsAreLoaded) {
            $this->loadElementsAndTexts();
        }
        if (array_key_exists($element->id, $this->_textsByElementId)) {
            return $this->_textsByElementId[$element->id];
        } else {
            return array();
        }
    }
    
    /**
     * Retrieve all of the record's ElementTexts for the given element name and
     * element set name.
     *
     * @param string $elementSetName Element set name
     * @param string $elementName Element name
     * @return array Set of ElementText records.
     */
    public function getElementTexts($elementSetName, $elementName)
    {
        $element = $this->getElement($elementSetName, $elementName);
        return $this->getElementTextsByRecord($element);
    }
    
    /**
     * Retrieve all of the record's ElementTexts, in order.
     *
     * @return array Set of ElementText records.
     */
    public function getAllElementTexts()
    {
        if (!$this->_recordsAreLoaded) {
            $this->loadElementsAndTexts();
        }
    
        return $this->_textsByNaturalOrder;
    }
    
    public function getAllMultiValues()
    {
       return $this->getTable('UserProfilesMultiValue')->findBy(array('profile_id'=>$this->id)); 
    }
    
    /**
     * Retrieve the Element records for the given ElementSet.
     *
     * @param string Element Set name
     * @return array Set of Element records
     */
    public function getElementsBySetName($elementSetName)
    {
        if (!$this->_recordsAreLoaded) {
            $this->loadElementsAndTexts();
        }
    
        $elements = @$this->_elementsBySet[$elementSetName];
        return !empty($elements) ? $elements : array();
    }
    
    /**
     * Retrieve ALL the Element records for the object, organized by ElementSet.
     * For example, $elements['Dublin Core'] = array(Element instance, Element instance, ...)
     *
     * @return array Set of Element records
     */
    public function getAllElements()
    {
        if (!$this->_recordsAreLoaded) {
            $this->loadElementsAndTexts();
        }
    
        return $this->_elementsBySet;
    }
    
    /**
     * Retrieve the Element record corresponding to the given element name and
     * element set name.
     *
     * @param string $elementSetName
     * @param string $elementName
     * @return Element
     */
    public function getElement($elementSetName, $elementName)
    {
        if (!$this->_recordsAreLoaded) {
            $this->loadElementsAndTexts();
        }
    
        $element = @$this->_elementsBySet[$elementSetName][$elementName];
        if (!$element) {
            throw new Omeka_Record_Exception(__('There is no element "%1$s", "%2$s"!', $elementSetName, $elementName));
        }
    
        return $element;
    }
    
    /**
     * Retrieve the Element with the given ID.
     *
     * @param int $elementId
     * @return Element
     */
    public function getElementById($elementId)
    {
        if (!$this->_recordsAreLoaded) {
            $this->loadElementsAndTexts();
        }
        if (!array_key_exists($elementId, $this->_elementsById)) {
            return false;
            // for compatibility with other plugins' forms, getElementById will skip elements not used in profiles
        }
    
        return $this->_elementsById[$elementId];
    }
    
    /**
     * Index a set of ElementTexts based on element ID.
     *
     * @param array $textRecords Set of ElementText records
     * @return array The provided ElementTexts, indexed by element ID.
     */
    private function _indexTextsByElementId($textRecords)
    {
        $indexed = array();
        foreach ($textRecords as $textRecord) {
            $indexed[$textRecord->element_id][] = $textRecord;
        }
    
        return $indexed;
    }
    
    /**
     * Index a set of Elements based on their name. The result is a doubly
     * associative array, with the first key being element set name and the second
     * being element name.
     *
     * i.e., $indexed['Dublin Core']['Creator'] = Element instance
     *
     * @param array $elementRecords Set of Element records
     * @return array The provided Elements, indexed as described
     */
    private function _indexElementsBySet(array $elementRecords)
    {
        $indexed = array();
        foreach($elementRecords as $record) {
            $indexed[$record->set_name][$record->name] = $record;
        }
        return $indexed;
    }
    
    /**
     * Indexes the elements returned by element ID.
     *
     * @param array
     * @return array
     */
    private function _indexElementsById(array $elementRecords)
    {
        $indexed = array();
        foreach($elementRecords as $record) {
            $indexed[$record->id] = $record;
        }
        return $indexed;
    }
    
    /**
     * Add a string of text for an element.
     *
     * Creates a new ElementText record, populates it with the specified text
     * value and assigns it to the element.
     *
     * saveElementTexts() must be called after this in order to save the element
     * texts to the database.
     *
     * @param Element $element Element which text should be created for
     * @param string $elementText Text to be added
     * @param bool $isHtml Whether the text to add is HTML
     */
    public function addTextForElement($element, $elementText, $isHtml = false)
    {
        if(!array_key_exists($element->id, $this->_elementsById)) {
            return;
        }
        $textRecord = new ElementText;
        $textRecord->record_id = $this->id;
        $textRecord->element_id = $element->id;
        $textRecord->record_type = $this->_getRecordType();
        $textRecord->text = $elementText;
        $textRecord->html = (int)$isHtml;
    
        $this->_textsToSave[] = $textRecord;
    }
    
    /**
     * Add element texts for a record based on a formatted array of values.
     * The array must be formatted as follows:
     *
     * <code>
     *              'Element Set Name' =>
     *                  array('Element Name' =>
     *                      array(array('text' => 'foo', 'html' => false)))
     * </code>
     *
     * Since 1.4, the array can also be formatted thusly:
     *
     * <code>
     *      array(
     *          array('element_id' => 1,
     *                'text' => 'foo',
     *                'html' => false)
     *      )
     * </code>
     *
     * @param array $elementTexts
     */
    public function addElementTextsByArray(array $elementTexts)
    {
        if (isset($elementTexts[0]['element_id'])) {
            $this->_addTextsByElementId($elementTexts);
        } else {
            $this->_addTextsByElementName($elementTexts);
        }
    }
    
    private function _addTextsByElementName(array $elementTexts)
    {
        foreach ($elementTexts as $elementSetName => $elements) {
            foreach ($elements as $elementName => $elementTexts) {
                $element = $this->getElement($elementSetName, $elementName);
                foreach ($elementTexts as $elementText) {
                    if (!array_key_exists('text', $elementText)) {
                        throw new Omeka_Record_Exception(__('Element texts are not formatted correctly!'));
                    }
                    // Only add the element text if it's not empty.  There
                    // should be no empty element texts in the DB.
                    if (!empty($elementText['text'])) {
                        $this->addTextForElement($element, $elementText['text'], $elementText['html']);
                    }
                }
            }
        }
    }
    
    private function _addTextsByElementId(array $texts)
    {
        foreach ($texts as $key => $info) {
            if (empty($info['text'])) {
                continue;
            }
            if(!array_key_exists($info['element_id'], $this->_elementsById)) {
                continue;
            }            
            $text = new ElementText;
            $text->record_type = $this->_getRecordType();
            $text->element_id = $info['element_id'];
            $text->record_id = $this->id;
            $text->text = $info['text'];
            $text->html = $info['html'];
            $this->_textsToSave[] = $text;
        }
    }
    
    /**
     * The application flow is thus:
     *  0) Validate required elements
     *  1) Build ElementText objects from the POST.
     *  2) Validate the ElementText objects and assign error messages if
     *     necessary.
     *  3) After the item saves correctly, delete all the ElementText records
     *     for the Item.
     *  4) Save the new ElementText objects to the database.
     *
     * @param array POST data
     */
    public function beforeSaveElements($post)
    {
        $this->_validateRequiredElements($post);
        $this->_getElementTextsToSaveFromPost($post);
        $this->_validateElementTexts();
    }
    
    /**
     * The POST should have a key called "Elements" that contains an array
     * that is keyed to an element's ID.  That array should contain all the
     * text values for that element. For example:
     *
     * <code>
     *
     * array('Elements' =>
     *             array(
     *                 '50' => array(array('text' => 'Foobar', //element id 50, e.g. DC:Title
     *                               'html' => 0
     *                               )),
     *                 '41' => array(array('text' => '<p>Baz baz baz</p>', //element id 41, e.g. DC:Description
     *                                'html' => 1
     *                               ))
     *                  )
     *      )
     *
     * </code>
     * @todo May want to throw an Exception if an element in the POST doesn't
     * actually exist.
     * @param array POST data
     */
    private function _getElementTextsToSaveFromPost($post)
    {
        if (!$elementPost = $post['Elements']) {
            return;
        }
    
        foreach ($elementPost as $elementId => $texts) {
            // Pull this from the list of prior retrieved data instead of a new SQL query each time.
            $element = $this->getElementById($elementId);
            if(!element) {
                continue;
            }
            // Add this to the stack of elements that are stored on the form.
            $this->_elementsOnForm[$element->id] = $element;
    
            foreach ($texts as $key => $textAttributes) {
                $elementText = $this->getTextStringFromFormPost($textAttributes, $element);
    
                // Save element text filter.
                $filterName = array('Save', $this->_getRecordType(), $element->set_name, $element->name);
                $elementText = apply_filters(
                        $filterName,
                        $elementText,
                        array('record' => $this, 'element' => $element)
                );
    
                // Ignore fields that are empty (no text)
                if (empty($elementText)) {
                    continue;
                }
    
                $isHtml = (int) (boolean) $textAttributes['html'];
                $this->addTextForElement($element, $elementText, $isHtml);
            }
        }
    }
    
    /**
     * Retrieve a text string for an element from POSTed form data.
     *
     * @param array POST data
     * @param Element
     * @return string
     */
    public function getTextStringFromFormPost($postArray, $element)
    {
        // Attempt to override the defaults with plugin behavior.
        $filterName = array(
                'Flatten',
                $this->_getRecordType(),
                $element->set_name,
                $element->name);
    
        // If no filters, this should return null.
        $flatText = null;
        $flatText = apply_filters(
                $filterName,
                $flatText,
                array('post_array' => $postArray, 'element' => $element)
        );
    
        // If we got something back, short-circuit the built-in processing.
        if ($flatText) {
            return $flatText;
        }
    
        return $postArray['text'];
    }
    
    /**
     * Validate all the elements one by one.  This is potentially a lot slower
     * than batch processing the form, but it gives the added bonus of being
     * able to encapsulate the logic for validation of Elements.
     */
    private function _validateElementTexts()
    {
        foreach ($this->_textsToSave as $key => $textRecord) {
            if (!$this->_elementTextIsValid($textRecord)) {
                $elementRecord = $this->getElementById($textRecord->element_id);
                if(!elementRecord) {
                    continue;
                }
                $errorMessage = __('The "%s" field has at least one invalid value!', $elementRecord->name);
                $this->addError($elementRecord->name, $errorMessage);
            }
        }
    }
    
    private function _validateRequiredElements($post)
    {
        $type = $this->getProfileType();
        $elementPost = $post['Elements'];
        if(isset($post['MultiElements'])) {
            $multiElementPost = $post['MultiElements'];
        } else {
            $multiElementPost = array();
        }
        
        foreach ($elementPost as $elementId => $texts) {
            // Pull this from the list of prior retrieved data instead of a new SQL query each time.
            // for compatibility with other plugins' forms, getElementById will skip elements not used in profiles
            $element = $this->getElementById($elementId);
            if(!$element) {
                continue;
            }
            // Add this to the stack of elements that are stored on the form.
            $this->_elementsOnForm[$element->id] = $element;
        
            foreach ($texts as $key => $textAttributes) {
                $elementText = $this->getTextStringFromFormPost($textAttributes, $element);
                if(in_array($elementId, $type->required_element_ids) && empty($elementText)) {
                    $errorMessage = __('The "%s" field is required.', $element->name);
                    $this->addError($element->name, $errorMessage);                
                }
            }
        }
        $postedMultiElements = array_keys($multiElementPost);
        foreach($type->required_multielement_ids as $requiredId) {
            if(!in_array($requiredId, $postedMultiElements)) {
                $multiEl = $this->getTable('UserProfilesMultiElement')->find($requiredId);
                $errorMessage = __('The "%s" field is required.', $multiEl->name);
                $this->addError($multiEl->name, $errorMessage);
            }
        }
    }
    
    /**
     * Return whether the given ElementText record is valid.
     *
     * @param ElementText $elementTextRecord
     * @return boolean
     */
    private function _elementTextIsValid($elementTextRecord)
    {
        $elementRecord = $this->getElementById($elementTextRecord->element_id);
        if(!elementRecord) {
            $isValid = true;
        }        
        $textValue = $elementTextRecord->text;
        // Start out as valid by default.
        $isValid = true;
    
        // Hook into this for plugins.
        // array('Validate', 'Item', 'Title', 'Dublin Core')
        // add_filter(array('Validate', 'Item', 'Title', 'Dublin Core'), 'my_filter_name');
    
        // function my_filter_name($isValid, $elementText, $args)
        // {
        //      $item = $args['item'];
        //      $element = $args['element'];
        //      if (!in_array($elementText, array('foo'))) {
        //          return false;
        //      }
        // }
    
        $filterName = array('Validate', $this->_getRecordType(), $elementRecord->set_name, $elementRecord->name);
        // Order of the parameters that are passed to this:
        // $isValid = the current value indicating whether or not the element text has validated.
        // $textValue = the string value that needs to be validated
        // $record = the Item or File or whatever record that the element text needs to apply to.
        // $element = the Element record that the text belongs to.
        $isValid = apply_filters(
                $filterName,
                $isValid,
                array(
                        'text' => $textValue,
                        'record' => $this,
                        'element' => $elementRecord,
                )
        );
        return $isValid;
    }
    
    /**
     * Save all ElementText records that were associated with a record.
     *
     * Typically called in the afterSave() hook for a record.
     */
    public function saveElementTexts()
    {
        if (!$this->exists()) {
            throw new Omeka_Record_Exception(__('Cannot save element text for records that are not yet persistent!'));
        }
    
        // Delete all the elements that were displayed on the form before adding the new stuff.
        $elementIdsFromForm = array_keys($this->_elementsOnForm);
        if (count($elementIdsFromForm)) {
            $this->deleteElementTextsByElementId($elementIdsFromForm);
        }
        foreach ($this->_textsToSave as $textRecord) {
            $textRecord->record_id = $this->id;
            $textRecord->save();
        }
    
        // Cause texts to be re-loaded if accessed after save.
        $this->_recordsAreLoaded = false;
    }
    
    /**
     * Delete all the element texts for element_id's that have been provided.
     *
     * @param array
     * @return boolean
     */
    public function deleteElementTextsByElementId(array $elementIdArray = array())
    {
        $db = $this->_getDb();
        $recordTypeName = $db->quote($this->_getRecordType());
        $id = $db->quote($this->id);
        $elements = $db->quote($elementIdArray);
    
        // For some reason, this needs the parameters to be quoted directly into the
        // SQL statement in order for the DELETE to work. It may have something to
        // do with quoting the array of element IDs into a string.
        $db->query(<<<SQL
DELETE FROM {$db->ElementText}
WHERE record_type = $recordTypeName
AND record_id = $id
AND element_id IN ($elements)
SQL
    );
    }
    
        /**
        * Delete all the element texts assigned to the current record ID.
        *
        * @return boolean
        */
        public function deleteElementTexts()
        {
        $db = $this->_getDb();
            $recordTypeName = $db->quote($this->_getRecordType());
            $id = $db->quote($this->id);
    
            $db->query(<<<SQL
            DELETE FROM {$db->ElementText}
            WHERE record_type = $recordTypeName
            AND record_id = $id
SQL
        );
    }
    
    /**
     * Returns whether or not the record has at least 1 element text
     *
     * @param string $elementSetName Element set name
     * @param string $elementName Element name
     * @return boolean
     */
     
    public function hasElementText($elementSetName, $elementName)
    {
        return ($this->getElementTextCount($elementSetName, $elementName) > 0);
    }
    
    /**
     * Returns the number of element texts for the record
     *
     * @param string $elementSetName Element set name
     * @param string $elementName Element name
     * @return boolean
     */
    public function getElementTextCount($elementSetName, $elementName)
    {
        return count($this->getElementTexts($elementSetName, $elementName));
    }    
    
    protected function beforeSave($args)
    {
        parent::beforeSave($args);
        if ($args['post']) {
            $post = $args['post'];
            $this->beforeSaveElements($post);
        }
        
    }
    
    public function getResourceId()
    {
        return 'UserProfiles_Profile';
    }
    
    public function getRecordUrl($action = 'user')
    {
        $user = $this->getOwner();
        $base = is_admin_theme() ? ADMIN_BASE_URL : PUBLIC_BASE_URL;
        switch($action) {
            case 'user':
                $url = "$base/user-profiles/profiles/user/id/{$user->id}";                
                break;
            case 'show':
                $type = $this->getProfileType();
                $url = "$base/user-profiles/profiles/user/id/{$user->id}?type=" . $type->id;
        }
        return $url;
    }
    
    public function getValuesForMulti($element)
    {
        $valuesObject = $this->getTable('UserProfilesMultiValue')->findByMultiElement($element);
        if($valuesObject) {
            return $valuesObject->getValues();
        } 
        return array();
        
        
    }
    
    public function getValueRecordForMulti($element)
    {
        return $this->getTable('UserProfilesMultiValue')->findByMultiElement($element);
    }
    
    public function saveMultiElementValues()
    {
        if(isset($_POST['MultiElements'])) {
            foreach($_POST['MultiElements'] as $multiElementId=>$values) {
                $multiElementValue = $this->getTable('UserProfilesMultiValue')->findByMultiElement($multiElementId);
                if(!$multiElementValue) {
                    $multiElementValue = new UserProfilesMultiValue();
                    $multiElementValue->profile_id = $this->id;
                    $multiElementValue->profile_type_id = $this->type_id;
                    $multiElementValue->multi_id = $multiElementId;    
                } 
                $multiElementValue->setValues($values);
                $multiElementValue->save();
            }
        }
    }
    
    
   
}