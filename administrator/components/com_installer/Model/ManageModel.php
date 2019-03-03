<?php
/**
 * @package     Joomla.Administrator
 * @subpackage  com_installer
 *
 * @copyright   Copyright (C) 2005 - 2018 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Installer\Administrator\Model;

defined('_JEXEC') or die;

use Joomla\CMS\Extension\ExtensionHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\FileLayout;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\Table\Extension;
use Joomla\CMS\Changelog\Changelog;
use Joomla\Component\Templates\Administrator\Table\StyleTable;
use Joomla\Database\DatabaseQuery;

/**
 * Installer Manage Model
 *
 * @since  1.5
 */
class ManageModel extends InstallerModel
{
	/**
	 * Constructor.
	 *
	 * @param   array                $config   An optional associative array of configuration settings.
	 * @param   MVCFactoryInterface  $factory  The factory.
	 *
	 * @see     \Joomla\CMS\MVC\Model\ListModel
	 * @since   1.6
	 */
	public function __construct($config = array(), MVCFactoryInterface $factory = null)
	{
		if (empty($config['filter_fields']))
		{
			$config['filter_fields'] = array(
				'status',
				'name',
				'client_id',
				'client', 'client_translated',
				'type', 'type_translated',
				'folder', 'folder_translated',
				'package_id',
				'extension_id',
			);
		}

		parent::__construct($config, $factory);
	}

	/**
	 * Method to auto-populate the model state.
	 *
	 * Note. Calling getState in this method will result in recursion.
	 *
	 * @param   string  $ordering   An optional ordering field.
	 * @param   string  $direction  An optional direction (asc|desc).
	 *
	 * @return  void
	 *
	 * @throws  \Exception
	 *
	 * @since   1.6
	 */
	protected function populateState($ordering = 'name', $direction = 'asc')
	{
		$app = Factory::getApplication();

		// Load the filter state.
		$this->setState('filter.search', $this->getUserStateFromRequest($this->context . '.filter.search', 'filter_search', '', 'string'));
		$this->setState('filter.client_id', $this->getUserStateFromRequest($this->context . '.filter.client_id', 'filter_client_id', null, 'int'));
		$this->setState('filter.status', $this->getUserStateFromRequest($this->context . '.filter.status', 'filter_status', '', 'string'));
		$this->setState('filter.type', $this->getUserStateFromRequest($this->context . '.filter.type', 'filter_type', '', 'string'));
		$this->setState('filter.folder', $this->getUserStateFromRequest($this->context . '.filter.folder', 'filter_folder', '', 'string'));
		$this->setState('filter.core', $this->getUserStateFromRequest($this->context . '.filter.core', 'filter_core', '', 'string'));

		$this->setState('message', $app->getUserState('com_installer.message'));
		$this->setState('extension_message', $app->getUserState('com_installer.extension_message'));
		$app->setUserState('com_installer.message', '');
		$app->setUserState('com_installer.extension_message', '');

		parent::populateState($ordering, $direction);
	}

	/**
	 * Enable/Disable an extension.
	 *
	 * @param   array  $eid    Extension ids to un/publish
	 * @param   int    $value  Publish value
	 *
	 * @return  boolean  True on success
	 *
	 * @throws  \Exception
	 *
	 * @since   1.5
	 */
	public function publish(&$eid = array(), $value = 1)
	{
		if (!Factory::getUser()->authorise('core.edit.state', 'com_installer'))
		{
			Factory::getApplication()->enqueueMessage(Text::_('JLIB_APPLICATION_ERROR_EDITSTATE_NOT_PERMITTED'), 'error');

			return false;
		}

		$result = true;

		/*
		 * Ensure eid is an array of extension ids
		 * TODO: If it isn't an array do we want to set an error and fail?
		 */
		if (!is_array($eid))
		{
			$eid = array($eid);
		}

		// Get a table object for the extension type
		$table = new Extension($this->getDbo());

		// Enable the extension in the table and store it in the database
		foreach ($eid as $i => $id)
		{
			$table->load($id);

			if ($table->type == 'template')
			{
				$style = new StyleTable($this->getDbo());

				if ($style->load(array('template' => $table->element, 'client_id' => $table->client_id, 'home' => 1)))
				{
					Factory::getApplication()->enqueueMessage(Text::_('COM_INSTALLER_ERROR_DISABLE_DEFAULT_TEMPLATE_NOT_PERMITTED'), 'notice');
					unset($eid[$i]);
					continue;
				}
			}

			if ($table->protected == 1)
			{
				$result = false;
				Factory::getApplication()->enqueueMessage(Text::_('JLIB_APPLICATION_ERROR_EDITSTATE_NOT_PERMITTED'), 'error');
			}
			else
			{
				$table->enabled = $value;
			}

			if (!$table->store())
			{
				$this->setError($table->getError());
				$result = false;
			}
		}

		// Clear the cached extension data and menu cache
		$this->cleanCache('_system', 0);
		$this->cleanCache('_system', 1);
		$this->cleanCache('com_modules', 0);
		$this->cleanCache('com_modules', 1);
		$this->cleanCache('mod_menu', 0);
		$this->cleanCache('mod_menu', 1);

		return $result;
	}

	/**
	 * Refreshes the cached manifest information for an extension.
	 *
	 * @param   int  $eid  extension identifier (key in #__extensions)
	 *
	 * @return  boolean  result of refresh
	 *
	 * @since   1.6
	 */
	public function refresh($eid)
	{
		if (!is_array($eid))
		{
			$eid = array($eid => 0);
		}

		// Get an installer object for the extension type
		$installer = Installer::getInstance();
		$result = 0;

		// Uninstall the chosen extensions
		foreach ($eid as $id)
		{
			$result |= $installer->refreshManifestCache($id);
		}

		return $result;
	}

	/**
	 * Remove (uninstall) an extension
	 *
	 * @param   array  $eid  An array of identifiers
	 *
	 * @return  boolean  True on success
	 *
	 * @throws  \Exception
	 *
	 * @since   1.5
	 */
	public function remove($eid = array())
	{
		if (!Factory::getUser()->authorise('core.delete', 'com_installer'))
		{
			Factory::getApplication()->enqueueMessage(Text::_('JERROR_CORE_DELETE_NOT_PERMITTED'), 'error');

			return false;
		}

		/*
		 * Ensure eid is an array of extension ids in the form id => client_id
		 * TODO: If it isn't an array do we want to set an error and fail?
		 */
		if (!is_array($eid))
		{
			$eid = array($eid => 0);
		}

		// Get an installer object for the extension type
		$installer = Installer::getInstance();
		$row = new \Joomla\CMS\Table\Extension($this->getDbo());

		// Uninstall the chosen extensions
		$msgs   = array();
		$result = false;

		foreach ($eid as $id)
		{
			$id = trim($id);
			$row->load($id);
			$result = false;

			$langstring = 'COM_INSTALLER_TYPE_TYPE_' . strtoupper($row->type);
			$rowtype    = Text::_($langstring);

			if (strpos($rowtype, $langstring) !== false)
			{
				$rowtype = $row->type;
			}

			if ($row->type)
			{
				$result = $installer->uninstall($row->type, $id);

				// Build an array of extensions that failed to uninstall
				if ($result === false)
				{
					// There was an error in uninstalling the package
					$msgs[] = Text::sprintf('COM_INSTALLER_UNINSTALL_ERROR', $rowtype);

					continue;
				}

				// Package uninstalled successfully
				$msgs[] = Text::sprintf('COM_INSTALLER_UNINSTALL_SUCCESS', $rowtype);
				$result = true;

				continue;
			}

			// There was an error in uninstalling the package
			$msgs[] = Text::sprintf('COM_INSTALLER_UNINSTALL_ERROR', $rowtype);
		}

		$msg = implode('<br>', $msgs);
		$app = Factory::getApplication();
		$app->enqueueMessage($msg);
		$this->setState('action', 'remove');
		$this->setState('name', $installer->get('name'));
		$app->setUserState('com_installer.message', $installer->message);
		$app->setUserState('com_installer.extension_message', $installer->get('extension_message'));

		// Clear the cached extension data and menu cache
		$this->cleanCache('_system', 0);
		$this->cleanCache('_system', 1);
		$this->cleanCache('com_modules', 0);
		$this->cleanCache('com_modules', 1);
		$this->cleanCache('com_plugins', 0);
		$this->cleanCache('com_plugins', 1);
		$this->cleanCache('mod_menu', 0);
		$this->cleanCache('mod_menu', 1);

		return $result;
	}

	/**
	 * Method to get the database query
	 *
	 * @return  DatabaseQuery  The database query
	 *
	 * @since   1.6
	 */
	protected function getListQuery()
	{
		$query = $this->getDbo()->getQuery(true)
			->select('*')
			->select('2*protected+(1-protected)*enabled AS status')
			->from('#__extensions')
			->where('state = 0');

		// Process select filters.
		$status   = $this->getState('filter.status');
		$type     = $this->getState('filter.type');
		$clientId = $this->getState('filter.client_id');
		$folder   = $this->getState('filter.folder');
		$core     = $this->getState('filter.core');

		if ($status !== '')
		{
			if ($status === '2')
			{
				$query->where('protected = 1');
			}
			elseif ($status === '3')
			{
				$query->where('protected = 0');
			}
			else
			{
				$query->where('protected = 0')
					->where('enabled = ' . (int) $status);
			}
		}

		if ($type)
		{
			$query->where('type = ' . $this->_db->quote($type));
		}

		if ($clientId !== '')
		{
			$query->where('client_id = ' . (int) $clientId);
		}

		if ($folder !== '')
		{
			$query->where('folder = ' . $this->_db->quote($folder == '*' ? '' : $folder));
		}

		if ($core !== '')
		{
			$coreExtensions = ExtensionHelper::getCoreExtensions();
			$elements       = array();

			foreach ($coreExtensions as $extension)
			{
				$elements[] = $this->getDbo()->quote($extension[1]);
			}

			if ($elements)
			{
				if ($core === '1')
				{
					$query->where($this->getDbo()->quoteName('element') . ' IN (' . implode(',', $elements) . ')');
				}
				elseif ($core === '0')
				{
					$query->where($this->getDbo()->quoteName('element') . ' NOT IN (' . implode(',', $elements) . ')');
				}
			}
		}

		// Process search filter (extension id).
		$search = $this->getState('filter.search');

		if (!empty($search) && stripos($search, 'id:') === 0)
		{
			$query->where('extension_id = ' . (int) substr($search, 3));
		}

		// Note: The search for name, ordering and pagination are processed by the parent InstallerModel class (in extension.php).

		return $query;
	}

	/**
	 * Load the changelog details for a given extension.
	 *
	 * @param   integer  $eid  The extension ID
	 *
	 * @return  string  The output to show in the modal.
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function loadChangelog($eid)
	{
		// Get the changelog URL
		$db    = $this->getDbo();
		$query = $db->getQuery(true)
			->select(
				$db->quoteName(
					array(
						'element',
						'type',
						'changelogurl'
					)
				)
			)
			->from($db->quoteName('#__extensions'))
			->where($db->quoteName('extension_id') . ' = ' . (int) $eid);
		$db->setQuery($query);

		$extension = $db->loadObject();

		if (!$extension->changelogurl)
		{
			return '';
		}

		$changelog = new Changelog;
		$changelog->loadFromXml($extension->changelogurl);

		// Get the changelog details
		$http   = HttpFactory::getHttp([], array('curl', 'stream'));
		$result = $http->get($extension->changelogurl);

		if ($result->code !== 200)
		{
			return '';
		}

		$xml = new \SimpleXMLElement($result->body);

		// Check if there is a changelog section
		if (!$xml->changelog)
		{
			return '';
		}

		// Get the changelog
		$changelog = $xml->changelog;

		// Validate the extension
		if ((string) $changelog->element !== $extension->element && (string) $changelog->type !== $extension->type)
		{
			return '';
		}

		// Read all the entries
		$entries = array(
			'security' => array(),
			'fix'      => array(),
			'addition' => array(),
			'change'   => array(),
			'removed'  => array(),
			'language' => array(),
			'note'     => array()
		);

		foreach ((array) $changelog as $changeType => $item)
		{
			if (!isset($entries[(string) $changeType]))
			{
				continue;
			}

			$entries[(string) $changeType] = array_values((array) $item->item);
		}

		$layout = new FileLayout('joomla.installer.changelog');
		$output = $layout->render($entries);

		return $output;
	}
}
