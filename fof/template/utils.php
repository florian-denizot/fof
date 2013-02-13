<?php
/**
 * @package    FrameworkOnFramework
 * @copyright  Copyright (C) 2010 - 2012 Akeeba Ltd. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE.txt
 */

// Protect from unauthorized access
defined('_JEXEC') or die();

class FOFTemplateUtils
{
	/**
	 * Add a CSS file to the page generated by the CMS
	 * 
	 * @param   string  $path  A fancy path definition understood by parsePath
	 * 
	 * @see FOFTemplateUtils::parsePath
	 */
	public static function addCSS($path)
	{
		$url = self::parsePath($path);
		JFactory::getDocument()->addStyleSheet($url);
	}

	/**
	 * Add a JS script file to the page generated by the CMS
	 * 
	 * @param   string  $path  A fancy path definition understood by parsePath
	 * 
	 * @see FOFTemplateUtils::parsePath
	 */
	public static function addJS($path)
	{
		$url = self::parsePath($path);
		JFactory::getDocument()->addScript($url);
	}
	
	/**
	 * Compile a LESS file into CSS and add it to the page generated by the CMS.
	 * This method has integrated cache support. The compiled LESS files will be
	 * written to the media/lib_fof/compiled directory of your site. If the file
	 * cannot be written we will use the $altPath, if specified
	 * 
	 * @param   string  $path     A fancy path definition understood by parsePath pointing to the source LESS file
	 * @param   string  $altPath  A fancy path definition understood by parsePath pointing to a precompiled CSS file, used when we can't write the generated file to the output directory
	 * 
	 * @return  mixed  True = successfully included generated CSS, False = the alternate CSS file was used, null = the source file does not exist
	 * 
	 * @see FOFTemplateUtils::parsePath
	 * 
	 * @since 2.0
	 */
	public static function addLESS($path, $altPath = null)
	{
		// Does the cache directory exists and is writeable
		static $sanityCheck = null;
		
		// Get the local LESS file
		$localFile = self::parsePath($path, true);
		
		jimport('joomla.filesystem.file');
		jimport('joomla.filesystem.folder');
		
		if (is_null($sanityCheck))
		{
			// Make sure the cache directory exists
			if (!JFolder::exists(JPATH_SITE . '/media/lib_fof/compiled/'))
			{
				$sanityCheck = JFolder::create(JPATH_SITE . '/media/lib_fof/compiled/');
			}
			else
			{
				$sanityCheck = true;
			}
		}
		
		// No point continuing if the source file is not there or we can't write to the cache
		if (!$sanityCheck || !JFile::exists($localFile))
		{
			if (is_string($altPath))
			{
				self::addCSS($altPath);
			}
			elseif(is_array($altPath))
			{
				foreach($altPath as $anAltPath)
				{
					self::addCSS($anAltPath);
				}
			}
			return false;
		}

		// Get the source file's unique ID
		$id = md5(filemtime($localFile) . filectime($localFile) . $localFile);
		
		// Get the cached file path
		$cachedPath = JPATH_SITE . '/media/lib_fof/compiled/' . $id . '.css';
		
		// Get the LESS compiler
		$lessCompiler = new FOFLess();
		$lessCompiler->formatterName = 'compressed';

		// Should I add an alternative import path?
		$altFiles = self::getAltPaths($path);
		if (isset($altFiles['alternate']))
		{
			$currentLocation = realpath(dirname($localFile));
			$normalLocation = realpath(dirname($altFiles['normal']));
			$alternateLocation = realpath(dirname($altFiles['alternate']));
			if ($currentLocation == $normalLocation)
			{
				$lessCompiler->importDir = array($alternateLocation, $currentLocation);
			}
			else
			{
				$lessCompiler->importDir = array($currentLocation, $normalLocation);
			}
		}
		
		// Compile the LESS file
		$lessCompiler->compileFile($localFile, $cachedPath);
		//$lessCompiler->checkedCompile($localFile, $cachedPath);
		
		// Add the compiled CSS to the page
		$base_url = rtrim(JUri::base(), '/');
		if (substr($base_url, -14) == '/administrator')
		{
			$base_url = substr($base_url, 0, -14);
		}
		$url = $base_url . '/media/lib_fof/compiled/' . $id . '.css';
		JFactory::getDocument()->addStyleSheet($url);
		
		return true;
	}
	
	/**
	 * Creates a SEF compatible sort header. Standard Joomla function will add a href="#" tag, so with SEF
	 * enabled, the browser will follow the fake link instead of processing the onSubmit event; so we
	 * need a fix.
	 *
	 * @param   string   $text   Header text
	 * @param   string   $field  Field used for sorting
	 * @param   JObject  $list   Object holding the direction and the ordering field
	 *
	 * @return  string  HTML code for sorting
	 */
	public static function sefSort($text, $field, $list)
	{
		$sort = JHTML::_('grid.sort', JText::_(strtoupper($text)).'&nbsp;',$field ,$list->order_Dir, $list->order);

		return str_replace('href="#"', 'href="javascript:void(0);"', $sort);
	}

	/**
	 * Parse a fancy path definition into a path relative to the site's root,
	 * respecting template overrides, suitable for inclusion of media files.
	 * For example, media://com_foobar/css/test.css is parsed into
	 * media/com_foobar/css/test.css if no override is found, or
	 * templates/mytemplate/media/com_foobar/css/test.css if the current
	 * template is called mytemplate and there's a media override for it.
	 *
	 * The valid protocols are:
	 * media://		The media directory or a media override
	 * admin://		Path relative to administrator directory (no overrides)
	 * site://		Path relative to site's root (no overrides)
	 *
	 * @param   string  $path       Fancy path
	 * @param   boolean $localFile  When true, it returns the local path, not the URL
	 * 
	 * @return  string  Parsed path
	 */
	public static function parsePath($path, $localFile = false)
	{
		if ($localFile)
		{
			$url = rtrim(JPATH_ROOT, DIRECTORY_SEPARATOR) . '/';
		}
		else
		{
			$url = JURI::root();
		}

		$altPaths = self::getAltPaths($path);
		$filePath = $altPaths['normal'];
		if (isset($altPaths['alternate']))
		{
			if (file_exists(JPATH_SITE . '/' . $altPaths['alternate']))
			{
				$filePath = $altPaths['alternate'];
			}
		}
		
		$url .= $filePath;

		return $url;
	}
	
	/**
	 * Parse a fancy path definition into a path relative to the site's root.
	 * It returns both the normal and alternative (template media override) path.
	 * For example, media://com_foobar/css/test.css is parsed into
	 * array(
	 *   'normal' => 'media/com_foobar/css/test.css',
	 *   'alternate' => 'templates/mytemplate/media/com_foobar/css//test.css'
	 * );
	 *
	 * The valid protocols are:
	 * media://		The media directory or a media override
	 * admin://		Path relative to administrator directory (no alternate)
	 * site://		Path relative to site's root (no alternate)
	 *
	 * @param   string  $path       Fancy path
	 * 
	 * @return  array  Array of normal and alternate parsed path
	 */
	public static function getAltPaths($path)
	{
		static $isCli = null;
		static $isAdmin = null;
		
		if(is_null($isCli) && is_null($isAdmin))
		{
			list($isCli, $isAdmin) = FOFDispatcher::isCliAdmin();
		}
		
		$protoAndPath = explode('://', $path, 2);
		if(count($protoAndPath) < 2) {
			$protocol = 'media';
		} else {
			$protocol = $protoAndPath[0];
			$path = $protoAndPath[1];
		}
		
		$path = ltrim($path, '/'.DIRECTORY_SEPARATOR);

		switch($protocol) {
			case 'media':
				// Do we have a media override in the template?
				$pathAndParams = explode('?', $path, 2);
				$altPath = 'templates/'.JFactory::getApplication()->getTemplate().'/media/';
				
				$ret = array(
					'normal'	=> 'media/' . $pathAndParams[0],
					'alternate'	=> ($isAdmin ? 'administrator/' : '') . $altPath . $pathAndParams[0],
				);
				break;

			case 'admin':
				$ret = array(
					'normal'	=> 'administrator/' . $path
				);
				break;

			default:
			case 'site':
				$ret = array(
					'normal'	=> $path
				);
				break;
		}

		return $ret;
	}

	/**
	 * Returns the contents of a module position
	 *
	 * @param string $position The position name, e.g. "position-1"
	 * @param int $style Rendering style; please refer to Joomla!'s code for more information
	 *
	 * @return string The contents of the module position
	 */
	public static function loadPosition($position, $style = -2)
	{
		$document	= JFactory::getDocument();
		$renderer	= $document->loadRenderer('module');
		$params		= array('style'=>$style);

		$contents = '';
		foreach (JModuleHelper::getModules($position) as $mod)  {
			$contents .= $renderer->render($mod, $params);
		}
		return $contents;
	}

	/**
	 * Merges the current url with new or changed parameters.
	 *
	 * This method merges the route string with the url parameters defined
	 * in current url. The parameters defined in current url, but not given
	 * in route string, will automatically reused in the resulting url.
	 * But only these following parameters will be reused:
	 *
	 * option, view, layout, format
	 *
	 * Example:
	 *
	 * Assuming that current url is:
	 * http://fobar.com/index.php?option=com_foo&view=cpanel
	 *
	 * <code>
	 * <?php echo FOFTemplateutils::route('view=categories&layout=tree'); ?>
	 * </code>
	 *
	 * Result:
	 * http://fobar.com/index.php?option=com_foo&view=categories&layout=tree
	 *
	 * @param string $route    The parameters string
	 * @return string          The human readable, complete url
	 */
	public static function route($route = '')
    {
        $route = trim($route);

        // Special cases
        if ($route == 'index.php' || $route == 'index.php?')
        {
            $result = $route;
        }
        else if (substr($route, 0, 1) == '&')
        {
            $url = JURI::getInstance();
            $vars = array();
            parse_str($route, $vars);

            $url->setQuery(array_merge($url->getQuery(true), $vars));

            $result = 'index.php?' . $url->getQuery();
        }
        else
        {

            $url = JURI::getInstance();
            $props = $url->getQuery(true);

            // Strip 'index.php?'
            if (substr($route, 0, 10) == 'index.php?')
            {
                $route = substr($route, 10);
            }

            // Parse route
            $parts = array();
            parse_str($route, $parts);
            $result = array();

            // Check to see if there is component information in the route if not add it
            if (!isset($parts['option']) && isset($props['option']))
            {
                $result[] = 'option=' . $props['option'];
            }

            // Add the layout information to the route only if it's not 'default'
            if (!isset($parts['view']) && isset($props['view']))
            {
                $result[] = 'view=' . $props['view'];
                if (!isset($parts['layout']) && isset($props['layout']))
                {
                    $result[] = 'layout=' . $props['layout'];
                }
            }

            // Add the format information to the URL only if it's not 'html'
            if (!isset($parts['format']) && isset($props['format']) && $props['format'] != 'html')
            {
                $result[] = 'format=' . $props['format'];
            }

            // Reconstruct the route
            if (!empty($route))
            {
                $result[] = $route;
            }

            $result = 'index.php?' . implode('&', $result);
        }

        return JRoute::_($result);
    }
}