<?php
/**
 * @link      http://fraym.org
 * @author    Dominik Weber <info@fraym.org>
 * @copyright Dominik Weber <info@fraym.org>
 * @license   http://www.opensource.org/licenses/gpl-license.php GNU General Public License, version 2 or later (see the LICENSE file)
 */
namespace Extension\HtmlEditor;

use Fraym\Block\BlockMetadata;
use \Fraym\Block\BlockXML as BlockXML;
use Fraym\Annotation\Registry;

/**
 * @package Extension\HtmlEditor
 * @Registry(
 * name="Html Editor",
 * description="Create your content with a rich text editor.",
 * version="1.0.0",
 * author="Fraym.org",
 * website="http://www.fraym.org",
 * repositoryKey="FRAYM_EXT_HTML",
 * entity={
 *      "\Fraym\Block\Entity\BlockExtension"={
 *          {
 *           "name"="Html Editor",
 *           "description"="Create formated text elements with a WYSIWYG Editor.",
 *           "class"="\Extension\HtmlEditor\HtmlEditor",
 *           "configMethod"="getBlockConfig",
 *           "execMethod"="execBlock",
 *           "saveMethod"="saveBlockConfig"
 *           },
 *      }
 * },
 * files={
 *      "Extension/HtmlEditor/*",
 *      "Extension/HtmlEditor/",
 *      "Template/Default/Extension/HtmlEditor/*",
 *      "Template/Default/Extension/HtmlEditor/",
 *      "Public/js/fraym/extension/htmleditor/*",
 *      "Public/js/fraym/extension/htmleditor/",
 * }
 * )
 * @Injectable(lazy=true)
 */
class HtmlEditor
{
    /**
     * @Inject
     * @var \Extension\HtmlEditor\HtmlEditorController
     */
    protected $htmlEditorController;

    /**
     * @Inject
     * @var \Fraym\Route\Route
     */
    protected $route;

    /**
     * @Inject
     * @var \Fraym\Block\BlockParser
     */
    protected $blockParser;

    /**
     * @Inject
     * @var \Fraym\Template\Template
     */
    protected $template;

    /**
     * @Inject
     * @var \Fraym\Database\Database
     */
    protected $db;

    /**
     * @Inject
     * @var \Fraym\Request\Request
     */
    public $request;

    /**
     * @param $blockId
     * @param BlockXML $blockXML
     * @return BlockXML
     */
    public function saveBlockConfig($blockId, \Fraym\Block\BlockXML $blockXML)
    {
        $blockConfig = $this->request->getGPAsObject();

        $customProperties = new \Fraym\Block\BlockXMLDom();
        foreach ($blockConfig->html as $localeId => $content) {
            $element = $customProperties->createElement('html');
            $domAttribute = $customProperties->createAttribute('locale');
            $domAttribute->value = $localeId;
            $element->appendChild($domAttribute);
            $element->appendChild($customProperties->createCDATASection($content));
            $customProperties->appendChild($element);
        }
        $blockXML->setCustomProperty($customProperties);
        return $blockXML;
    }

    /**
     * @param $xml
     * @return mixed
     */
    public function execBlock($xml)
    {
        $currentLocaleId = $this->route->getCurrentMenuItemTranslation()->locale->id;
        $locales = $xml->xpath('html[@locale="' . $currentLocaleId . '"]');
        $html = trim((string)current($locales));
        // set template content for custom templates
        $this->htmlEditorController->renderHtml($this->replaceLinkTags($html));
    }

    /**
     * @param null $blockId
     */
    public function getBlockConfig($blockId = null)
    {
        $configXml = null;
        if ($blockId) {
            $block = $this->db->getRepository('\Fraym\Block\Entity\Block')->findOneById($blockId);
            $configXml = $this->blockParser->getXMLObjectFromString($this->blockParser->wrapBlockConfig($block));
        }
        $this->htmlEditorController->getBlockConfig($configXml);
    }

    /**
     * @return array
     */
    public function buildMenuItemArray()
    {
        $menuItems = array();
        $locales = $this->db->getRepository('\Fraym\Locale\Entity\Locale')->findAll();
        foreach ($locales as $locale) {
            foreach ($locale->menuItemTranslations as $menuItemTranslation) {
                $menuItems[] = array($menuItemTranslation->title . " ({$locale->name})", $menuItemTranslation->id);
            }
        }
        return $menuItems;
    }

    /**
     * @param $blockHtml
     * @return mixed
     */
    public function replaceLinkTags($blockHtml)
    {
        $callback = function ($matches) {
            $id = trim(trim($matches[1], '"'), "'");

            if (is_numeric($id)) {

                $menuItemTranslation = $this->db->getRepository('\Fraym\Menu\Entity\MenuItemTranslation')->findOneById($id);

                if ($menuItemTranslation) {
                    return ' href="' . $this->route->buildFullUrl($menuItemTranslation->menuItem, true) . '"';
                }
            }
            return $matches[0];
        };

        return preg_replace_callback(
            '#\s*(?i)href\s*=\s*(\"([^"]*\")|\'[^\']*\'|([^\'">\s]+))#si',
            $callback,
            $blockHtml
        );
    }
}
