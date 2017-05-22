<?php
/**
 * Tinebase Abstract export class
 *
 * @package     Tinebase
 * @subpackage  Export
 * @license     http://www.gnu.org/licenses/agpl.html AGPL Version 3
 * @author      Paul Mehrer <p.mehrer@metaways.de>
 * @copyright   Copyright (c) 2017-2017 Metaways Infosystems GmbH (http://www.metaways.de)
 *
 */

/**
 * Tinebase Abstract export class
 *
 * @package     Tinebase
 * @subpackage    Export
 *
 */
abstract class Tinebase_Export_Abstract implements Tinebase_Record_IteratableInterface
{
    /**
     * default export definition name
     *
     * @var string
     */
    protected $_defaultExportname = 'default';

    /**
     * the record controller
     *
     * @var Tinebase_Controller_Record_Abstract
     */
    protected $_controller = NULL;

    /**
     * translation object
     *
     * @var Zend_Translate
     *
    protected $_translate;

    /**
     * locale object
     *
     * @var Zend_Locale
     *
    protected $_locale;*/

    /**
     * export config
     *
     * @var Zend_Config_Xml
     */
    protected $_config = array();

    /**
     * @var string application name of this export class
     */
    protected $_applicationName = null;

    /**
     * the record model
     *
     * @var string
     */
    protected $_modelName = null;

    /**
     * filter to generate export for
     *
     * @var Tinebase_Model_Filter_FilterGroup
     */
    protected $_filter = null;

    /**
     * sort records by this field (array keys: sort / dir / ...)
     *
     * @var array
     * @see Tinebase_Model_Pagination
     */
    protected $_sortInfo = array();

    /**
     * preference key if users can have different export configs
     *
     * @var string
     */
    protected $_prefKey = NULL;

    /**
     * format strings
     *
     * @var string
     */
    protected $_format = NULL;

    /**
     * custom field names for this model
     *
     * @var array
     */
    protected $_customFieldNames = NULL;

    /**
     * user fields to resolve
     *
     * @var array
     */
    protected $_userFields = array('created_by', 'last_modified_by', 'account_id');

    /**
     * first iteration (helper to write generic headings, etc.)
     *
     * @var boolean
     */
    protected $_firstIteration = true;

    /**
     * helper to determine if we are done with record processing
     *
     * @var bool
     */
    protected $_iterationDone = false;

    /**
     * just dump all properties of the records to _writeValue (through _getValue($field, $record) of course)
     *
     * @var boolean
     */
    protected $_dumpRecords = true;

    /**
     * write a generic header based on the properties of a record created from _modelName
     *
     * @var boolean
     */
    protected $_writeGenericHeader = true;

    /**
     * class cache for field config from _config->columns->column
     *
     * @var array
     */
    protected $_fieldConfig = array();

    /**
     * fields with special treatment in addBody
     *
     * @var array
     */
    protected $_specialFields = array();

    /**
     * if set to true _hasTwig() will return true in any case
     *
     * @var boolean
     */
    protected $_hasTemplate = false;

    /**
     * @var Twig_TemplateWrapper|null
     */
    protected $_twigTemplate = null;

    /**
     * @var string
     */
    protected $_templateFileName = null;

    protected $_resolvedFields = array();

    /**
     * @var Tinebase_DateTime|null
     */
    protected $_exportTimeStamp = null;

    /**
     * the constructor
     *
     * @param Tinebase_Model_Filter_FilterGroup $_filter
     * @param Tinebase_Controller_Record_Interface $_controller (optional)
     * @param array $_additionalOptions (optional) additional options
     */
    public function __construct(Tinebase_Model_Filter_FilterGroup $_filter, Tinebase_Controller_Record_Interface $_controller = NULL, $_additionalOptions = array())
    {
        $this->_filter = $_filter;
        if (! $this->_modelName) {
            $this->_modelName = $this->_filter->getModelName();
        }
        if (! $this->_applicationName) {
            $this->_applicationName = $this->_filter->getApplicationName();
        }

        $this->_controller = ($_controller !== NULL) ? $_controller : Tinebase_Core::getApplicationInstance($this->_applicationName, $this->_modelName);
        //$this->_translate = Tinebase_Translation::getTranslation($this->_applicationName);
        $this->_config = $this->_getExportConfig($_additionalOptions);
        if ($this->_config->template) {
            $this->_templateFileName = $this->_config->template;
        }
        //$this->_locale = Tinebase_Core::get(Tinebase_Core::LOCALE);
        $this->_exportTimeStamp = Tinebase_DateTime::now();

        if (isset($_additionalOptions['sortInfo'])) {
            if (isset($_additionalOptions['sortInfo']['field'])) {
                $this->_sortInfo['sort'] = $_additionalOptions['sortInfo']['field'];
                $this->_sortInfo['dir'] = isset($_additionalOptions['sortInfo']['direction']) ? $_additionalOptions['sortInfo']['direction'] : 'ASC';
            } else {
                $this->_sortInfo =  $_additionalOptions['sortInfo'];
            }
        }
    }

    /**
     * get export config
     *
     * @param array $_additionalOptions additional options
     * @return Zend_Config_Xml
     * @throws Tinebase_Exception_NotFound
     */
    protected function _getExportConfig($_additionalOptions = array())
    {
        if ((isset($_additionalOptions['definitionFilename']) || array_key_exists('definitionFilename', $_additionalOptions))) {
            // get definition from file
            $definition = Tinebase_ImportExportDefinition::getInstance()->getFromFile(
                $_additionalOptions['definitionFilename'],
                Tinebase_Application::getInstance()->getApplicationByName($this->_applicationName)->getId()
            );

        } else if ((isset($_additionalOptions['definitionId']) || array_key_exists('definitionId', $_additionalOptions))) {
            $definition = Tinebase_ImportExportDefinition::getInstance()->get($_additionalOptions['definitionId']);

        } else {
            // get preference from db and set export definition name
            $exportName = $this->_defaultExportname;
            if ($this->_prefKey !== NULL) {
                $exportName = Tinebase_Core::getPreference($this->_applicationName)->getValue($this->_prefKey, $exportName);
            }

            // get export definition by name / model
            $filter = new Tinebase_Model_ImportExportDefinitionFilter(array(
                array('field' => 'model', 'operator' => 'equals', 'value' => $this->_modelName),
                array('field' => 'name',  'operator' => 'equals', 'value' => $exportName),
            ));
            $definitions = Tinebase_ImportExportDefinition::getInstance()->search($filter);
            if (count($definitions) == 0) {
                throw new Tinebase_Exception_NotFound('Export definition for model ' . $this->_modelName . ' not found.');
            }
            $definition = $definitions->getFirstRecord();
        }

        $config = Tinebase_ImportExportDefinition::getInstance()->getOptionsAsZendConfigXml($definition, $_additionalOptions);

        if (Tinebase_Core::isLogLevel(Zend_Log::TRACE)) {
            Tinebase_Core::getLogger()->trace(__METHOD__ . '::' . __LINE__ . ' export config: ' . print_r($config->toArray(), TRUE));
        }

        return $config;
    }

    /**
     * get custom field names for this app
     *
     * @return array
     */
    protected function _getCustomFieldNames()
    {
        if ($this->_customFieldNames === NULL) {
            $this->_customFieldNames = Tinebase_CustomField::getInstance()->getCustomFieldsForApplication($this->_applicationName, $this->_modelName)->name;
        }

        return $this->_customFieldNames;
    }

    protected function _getTemplateFilename()
    {
        return $this->_templateFileName;
    }

    /**
     * get export format string (csv, ...)
     *
     * @return string
     * @throws Tinebase_Exception_NotFound
     */
    public function getFormat()
    {
        if ($this->_format === NULL) {
            throw new Tinebase_Exception_NotFound('Format string not found.');
        }

        return $this->_format;
    }

    /**
     * get download content type
     *
     * @return string
     */
    abstract public function getDownloadContentType();

    /**
     * return download filename
     *
     * @param string $_appName
     * @param string $_format
     * @return string
     */
    public function getDownloadFilename($_appName, $_format)
    {
        return 'export_' . strtolower($_appName) . '.' . $_format;
    }


    /**
     * workflow
     * generate();
     * * _exportRecords();
     * * * if _hasTwig()
     * * * * _loadTwig();
     * * * * * _getTwigSource();
     * * processIteration();
     * * * _resolveRecords();
     * * * if _firstIteration && _writeGenericHeader
     * * * * _writeGenericHead();
     * * * foreach $records
     * * * * _startRow();
     * * * * _processRecord();
     * * * * _endRow();
     * * _onAfterExportRecords();
     */
    /**
     * generate export
     */
    abstract public function generate();


    /**
     * export records
     */
    protected function _exportRecords()
    {
        if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__
            . ' Starting export of ' . $this->_modelName . ' with filter: ' . print_r($this->_filter->toArray(), true)
            . ' and sort info: ' . print_r($this->_sortInfo, true));

        if (true === $this->_hasTwig()) {
            $this->_loadTwig();
        }

        $this->_onBeforeExportRecords();

        $iterator = new Tinebase_Record_Iterator(array(
            'iteratable' => $this,
            'controller' => $this->_controller,
            'filter'     => $this->_filter,
            'options'     => array(
                'searchAction' => 'export',
                'sortInfo'     => $this->_sortInfo,
                'getRelations' => $this->_getRelations,
            ),
        ));

        $this->_firstIteration = true;
        $result = $iterator->iterate();

        $this->_onAfterExportRecords($result);

        if (Tinebase_Core::isLogLevel(Zend_Log::INFO)) Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__
            . ' Exported ' . $result['totalcount'] . ' records.');
    }

    protected function _onBeforeExportRecords()
    {}

    /**
     * @return bool
     */
    protected function _hasTwig()
    {
        if (true === $this->_hasTemplate) {
            return true;
        }
        if ($this->_config->columns && $this->_config->columns->column) {
            foreach($this->_config->columns->column as $column) {
                if ($column->twig) {
                    return true;
                }
            }
        }
        return false;
    }

    // TODO clean this up
    // unique template name!
    // cache dir?
    // autoescaping to be json conform!
    protected function _loadTwig()
    {
        if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' loading twig template...');

        $twigTemplateName = $this->_getUniqueTemplateName();

        $tineTwigLoader = new Tinebase_Twig_CallBackLoader($twigTemplateName, $this->_getLastModifiedTimeStamp(),
            array($this, '_getTwigSource'));

        $cacheDir = rtrim(Tinebase_Core::getTempDir(), '/') . '/tine20Twig';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }

        $twig = new Twig_Environment($tineTwigLoader, array(
            //'strict_variables' => true,
            'auto_reload' => true, // <- TODO only on dev env true?
            //'autoescape' => 'json shubidubidu',
            'cache' => $cacheDir
        ));

        /*if (true === $this->_hasTemplate) {
            $lexer = new Twig_Lexer($twig, array(
                'tag_block' => array('{$%', '%}'),
                'tag_variable' => array('{$', '}'),
            ));
            $twig->setLexer($lexer);
        }*/

        $this->_twigTemplate = $twig->load($twigTemplateName);
    }

    /**
     * @return string
     */
    public function _getTwigSource()
    {
        $source = '[';
        if (true !== $this->_hasTemplate && $this->_config->columns && $this->_config->columns->column) {
            foreach ($this->_config->columns->column as $column) {
                if ($column->twig) {
                   $source .= ($source!=='' ? ',"' : '""') . (string)$column->twig . '"';
                }
            }
        }
        return $source . ']';
    }

    /**
     * TODO fix this
     *
     * @return string
     */
    protected function _getUniqueTemplateName()
    {
        return uniqid();
    }

    /**
     * TODO fix this
     *
     * @return int
     */
    protected function _getLastModifiedTimeStamp()
    {
        return time();
    }

    /**
     * add body rows
     *
     * @param Tinebase_Record_RecordSet $_records
     */
    public function processIteration($_records)
    {
        if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' iterating over export data...');

        $this->_resolveRecords($_records);

        if (true === $this->_firstIteration && true === $this->_writeGenericHeader) {
            $this->_writeGenericHead();
        }

        foreach($_records as $record) {

            $this->_startRow();

            $this->_processRecord($record);

            $this->_endRow();
        }

        $this->_firstIteration = false;
    }

    /**
     * resolve records and prepare for export (set user timezone, ...)
     *
     * @param Tinebase_Record_RecordSet $_records
     */
    protected function _resolveRecords(Tinebase_Record_RecordSet $_records)
    {
        if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' resolving export records...');
        // FIXME think what to do
        // TODO fix ALL this!

        // get field types/identifiers from config
        $identifiers = array();
        if ($this->_config->columns) {
            $types = array();
            foreach ($this->_config->columns->column as $column) {
                $types[] = $column->type;
                $identifiers[] = $column->identifier;
            }
            $types = array_unique($types);
        } else {
            $types = $this->_resolvedFields;
        }

        // resolve users
        foreach ($this->_userFields as $field) {
            if (in_array($field, $types) || in_array($field, $identifiers)) {
                if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' Resolving users for ' . $field);
                Tinebase_User::getInstance()->resolveMultipleUsers($_records, $field, TRUE);
            }
        }

        // add notes
        if (in_array('notes', $types)) {
            Tinebase_Notes::getInstance()->getMultipleNotesOfRecords($_records, 'notes', 'Sql', FALSE);
        }

        // add container
        if (in_array('container_id', $types)) {
            Tinebase_Container::getInstance()->getGrantsOfRecords($_records, Tinebase_Core::getUser());
        }

        $_records->setTimezone(Tinebase_Core::getUserTimezone());
    }

    protected function _writeGenericHead()
    {
        if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' writing generic header...');

        $this->_startRow();

        if ($this->_config->columns && $this->_config->columns->column) {
            foreach($this->_config->columns->column as $column) {
                if ($column->header) {
                    $this->_writeValue($column->header);
                } elseif ($column->recordProperty) {
                    $this->_writeValue($column->recordProperty);
                } else {
                    $this->_writeValue('');
                }
            }
        } else {
            /** @var Tinebase_Record_Abstract $record */
            $record = new $this->_modelName(array(), true);

            foreach ($record->getFields() as $field) {
                // TODO translate?
                $this->_writeValue($field);
            }
        }

        $this->_endRow();
    }

    protected function _startRow() {}

    /**
     * @param Tinebase_Record_Interface $_record
     */
    protected function _processRecord(Tinebase_Record_Interface $_record = null)
    {
        if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' processing a export record...');

        if (true === $this->_dumpRecords) {
            foreach($_record->getFields() as $field) {
                $this->_writeValue($this->_convertToString($_record->{$field}));
            }

        } elseif (true !== $this->_hasTemplate) {
            $twigResult = array();
            if (null !== $this->_twigTemplate) {
                $result = json_decode($this->_twigTemplate->render($this->_getTwigContext(array('record' => $_record))));
                if (is_array($result)) {
                    $twigResult = $result;
                } else {
                    if (Tinebase_Core::isLogLevel(Zend_Log::WARN)) Tinebase_Core::getLogger()->warn(__METHOD__ . '::' . __LINE__ .
                        ' twig render and json_decode did not return an array: ' . print_r($result, true));
                }
            }
            $twigCounter = 0;
            foreach($this->_config->columns->column as $column) {
                if ($column->twig) {
                    if (isset($twigResult[$twigCounter]) || array_key_exists($twigCounter, $twigResult)) {
                        $this->_writeValue($this->_convertToString($twigResult[$twigCounter]));
                    } else {
                        if (Tinebase_Core::isLogLevel(Zend_Log::WARN)) Tinebase_Core::getLogger()->warn(__METHOD__ . '::' . __LINE__ .
                            ' twig column: ' . $column->twig . ' not found in twig result array');
                        $this->_writeValue('');
                    }
                } elseif($column->recordProperty) {
                    $this->_writeValue($this->_convertToString($_record->{$column->recordProperty}));
                } else {
                    if (Tinebase_Core::isLogLevel(Zend_Log::INFO)) Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ .
                        ' pointless column found: ' . print_r($column, true));
                }
            }

        } elseif (null !== $this->_twigTemplate) {
            $twigResult = json_decode($this->_twigTemplate->render($this->_getTwigContext(array('record' => $_record))));
            if (!is_array($twigResult)) {
                if (Tinebase_Core::isLogLevel(Zend_Log::WARN)) Tinebase_Core::getLogger()->warn(__METHOD__ . '::' . __LINE__ .
                    ' twig render and json_decode did not return an array: ' . print_r($twigResult, true));
                return;
            }

            foreach($this->_twigMapping as $key => $twigKey) {
                if (isset($twigResult[$key]) || array_key_exists($key, $twigResult)) {
                    $value = $this->_convertToString($twigResult[$key]);
                } else {
                    if (Tinebase_Core::isLogLevel(Zend_Log::WARN)) Tinebase_Core::getLogger()->warn(__METHOD__ . '::' . __LINE__ .
                        ' twig mapping: ' . $key . ' ' . $twigKey . ' not found in twig result array');
                    $value = '';
                }
                $this->_setValue($twigKey, $value);
            }

        } else {
            if (Tinebase_Core::isLogLevel(Zend_Log::WARN)) Tinebase_Core::getLogger()->warn(__METHOD__ . '::' . __LINE__ . ' can not process record, misconfigured!');
        }
    }

    /**
     * @param array $context
     * @return array
     */
    protected function _getTwigContext(array $context)
    {

        // TODO treat branding logo, add path, and protocol!

        return array_merge(array(
            'branding'          => array(
                'logo'              => Tinebase_Config::getInstance()->{Tinebase_Config::BRANDING_LOGO},
                'title'             => Tinebase_Config::getInstance()->{Tinebase_Config::BRANDING_TITLE},
                'description'       => Tinebase_Config::getInstance()->{Tinebase_Config::BRANDING_DESCRIPTION},
                'weburl'            => Tinebase_Config::getInstance()->{Tinebase_Config::BRANDING_WEBURL},
            ),
            'export'            => array(
                'timestamp'         => $this->_exportTimeStamp,
                'account'           => Tinebase_Core::getUser(),
            )
        ), $context);
    }

    /**
     * @param string $_key
     * @param string $_value
     */
    abstract protected function _setValue($_key, $_value);

    /**
     * @param string $_value
     */
    abstract protected function _writeValue($_value);

    /**
     * @param mixed $_value
     * @return string
     */
    protected function _convertToString($_value)
    {
        if (is_null($_value)) {
            $_value = '';
        }

        if ($_value instanceof DateTime) {
            $_value = Tinebase_Translation::dateToStringInTzAndLocaleFormat($_value, null, null, $this->_config->datetimeformat);
        }

        if (is_object($_value) && method_exists($_value, '__toString')) {
            $_value = $_value->__toString();
        }

        if (!is_scalar($_value)) {
            $_value = '';
        }

        return (string)$_value;
    }

    protected function _endRow() {}

    /**
     * set generic data
     *
     * @param array $result
     */
    protected function _onAfterExportRecords(/** @noinspection PhpUnusedParameterInspection */ array $result)
    {
        $this->_iterationDone = true;

        if (null !== $this->_twigTemplate) {
            $this->_processRecord(null);
        }
    }
}