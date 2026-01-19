<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  content.slt_comments
 *
 * @copyright   (C) 2026 www.codersite.ru
 * @license     GNU General Public License version 2 or later;
 */

namespace SLT\Plugin\Content\SltComments\Extension;

use Joomla\CMS\Factory;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Event\Content\AfterDisplayEvent;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\SubscriberInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Session\Session;

\defined('_JEXEC') or die;

final class SltComments extends CMSPlugin implements SubscriberInterface
{
	protected object $componentParams;
    protected $db;
	public function __construct(&$subject, $config = [])
	{
		parent::__construct($subject, $config);
		$this->componentParams = ComponentHelper::getParams('com_slt_comments');
        $this->db = Factory::getContainer()->get(DatabaseInterface::class);
	}

	public static function getSubscribedEvents(): array
	{
		return [
			'onContentAfterDisplay' => 'onContentAfterDisplay',
			'onAjaxSltCommentsSubmit' => 'onAjaxSltCommentsSubmit',
			'onAjaxSltCommentsLike' => 'onAjaxSltCommentsLike',
		];
	}

	public function onContentAfterDisplay(AfterDisplayEvent $event) : void
	{
        $this->loadLanguage();
		// Выводим комментарии в позиции "afterDisplayContent"
		$app = $this->getApplication();
		if (!$app->isClient('site')) return; // Только в публичной части
		$context = $event->getContext();
		if ($context !== "com_content.article" && $context !== "com_content.featured") return; // Только в материалах

		$item = $event->getArgument('item');

        $catidvisible = $this->componentParams->get('catidvisible', 'default_value');
		if (empty($catidvisible) || !in_array($item->catid,$catidvisible)) return; // Только в выбранных категориях

        $showPolicy = $this->componentParams->get('show_policy') ?? false;

		$query = $this->db->getQuery(true)
			->select('*')
			->from($this->db->quoteName('#__slt_comments'))
			->where($this->db->quoteName('id_content') . ' = ' . $item->id)
            ->where($this->db->quoteName('state') . ' IN (0, 1)') // берем опубликованные и не опубликованные, не опубликованные для вывода с пометкой "На модерации" для конкретного пользователя
			->order($this->db->quoteName('date_creation') . ' ' .$this->componentParams->get('sort', 'DESC'));

        $this->db->setQuery($query);
		$commentsData = $this->db->loadObjectList();

        $formData = [
            'articleID' => $item->id,
            'limitComment' => $this->componentParams->get('limit_comment', 1000),
            'uid' => $this->getCookieId() ?? '',
        ];
        $policyHtml = false;
        if ($showPolicy) {
            $linkPolicy = $this->componentParams->get('policy') ?? '';
            $linkAgreement = $this->componentParams->get('agreement') ?? '';
            $textPolicy = $this->componentParams->get('template_text_policy') ?? '';
            $policyHtml = str_replace(
                ['{{comment_policy}}', '{{comment_agreement}}'],
                [htmlspecialchars($linkPolicy ?: '#', ENT_QUOTES), htmlspecialchars($linkAgreement ?: '#', ENT_QUOTES)],
                $textPolicy
            );
            //Log::add(print_r($policyHtml,true), Log::INFO, 'log');
        }
        $formData['textPolicy'] = $policyHtml;

        $commentsArray = ['itemId' => $item->id,'formData' => $formData];

		if(!empty($commentsData)){
            $countActiveComments = 0;
            foreach ($commentsData as $comment) {
                if ($comment->state == 1) {
                    $countActiveComments++;
                }
            }
			$commentsArray['comments'] = $commentsData;
            $commentsArray['countActiveComments'] = $countActiveComments;
		}
        //Log::add(print_r($commentsArray,true), Log::INFO, 'log');
        $resultComments = LayoutHelper::render('components.slt_comments.comments.comments', $commentsArray);
		$wa = $app->getDocument()->getWebAssetManager();
		$wa->registerAndUseScript('slt.comments.form', 'com_slt_comments/sendForm.js', [], ['defer' => true]);
		$event->addResult($resultComments);
	}
	public function onAjaxSltCommentsSubmit()
	{
        $app = $this->getApplication();
		$params = $this->componentParams;

		$input = $app->input;

		if (!Session::checkToken()) {
			echo new JsonResponse(['error' => Text::_('JINVALID_TOKEN')]);
			exit;
		}

		$data = $input->post->getArray();

		$name = trim($data['name_author'] ?? '');
		$comment = trim($data['comment'] ?? '');
		$contentItemId = (int)($data['content_item_id'] ?? 0);
		$parentId = (int)($data['parent_id'] ?? 0);

        if (empty($name) || empty($comment) || !$contentItemId) {
			echo new JsonResponse(['error' => 'Не все обязательные поля заполнены']);
			exit;
		}

		$name = htmlspecialchars(strip_tags($name));
		$comment = htmlspecialchars(strip_tags($comment));
        $uid = $this->setCookieId() ?? '';

		$query = $this->db->getQuery(true);

		$columns = ['name_author', 'comment', 'id_content', 'date_creation', 'state', 'id_parent','uid'];
		$values = [$this->db->quote($name), $this->db->quote($comment), $contentItemId, $this->db->quote(Factory::getDate()->toSql()), $params->get('state', '0'),$parentId,$this->db->quote($uid)];

		$query->insert($this->db->quoteName('#__slt_comments'))
			->columns($this->db->quoteName($columns))
			->values(implode(',', $values));

        $this->db->setQuery($query);
        $this->db->execute();

		// Получаем ID последней вставленной записи
		$insertedIdComment = $this->db->insertid();

		echo new JsonResponse(['success' => true, 'message' => 'Комментарий успешно добавлен', 'idComment' => $insertedIdComment]);
		exit;
	}

    private function setCookieId () : string
    {
        $app = $this->getApplication();
        $cookie = $app->input->cookie;

        $cookieId = $cookie->getString('SLT_COOKIE_UID');
        if (!empty($cookieId)) return $cookieId;

        $token = Session::getFormToken();
        $salt = $app->getConfig()->get('secret') ?? 'salt';
        $uid = md5($token . $salt);; // CSRF токен + соль

        if (!empty($uid)){
            $cookie->set('SLT_COOKIE_UID', $uid, time() + 365*24*3600, '/');
            return $uid;
        }
        return '';
    }
    private function getCookieId () : string
    {
        $app = $this->getApplication();
        $cookie = $app->GetInput()->cookie;
        $cookieId = $cookie->getString('SLT_COOKIE_UID');
        if (!empty($cookieId)){
            //Log::add(print_r($cookieId, true), Log::INFO, 'log');
            return $cookieId;
        }
        return '';

    }
	public function onAjaxSltCommentsLike()
	{
		if (!Session::checkToken()) {
			echo new JsonResponse(['error' => Text::_('JINVALID_TOKEN')]);
			exit;
		}
		$input = Factory::getApplication()->input;
		$data = $input->post->getArray();
        //Log::add(print_r($data, true), Log::INFO, 'log');
		$type = trim($data['type'] ?? null);
		$idComment = (int) trim($data['idComment'] ?? null);
		if (empty($type) && empty($idComment)) {
			echo new JsonResponse(['error' => 'Не все обязательные поля заполнены']);
			exit;
		}
        $count = $this->getLikes($type, $idComment);

		echo new JsonResponse(['success' => true, 'message' => 'Спасибо за лайк!', 'count' => $count]);
	}
    private function getLikes($type, $idComment) : array
    {
        $type = ($type === 'dislike') ? 'dislike' : 'like';

        $incField = ($type === 'like') ? 'likes' : 'dislikes';
        $decField = ($type === 'like') ? 'dislikes' : 'likes';

        $query = "UPDATE " . $this->db->quoteName('#__slt_comments') . " 
              SET " . $this->db->quoteName($incField) . " = " .
            $this->db->quoteName($incField) . " + 1,
                  " . $this->db->quoteName($decField) . " = 
                  CASE WHEN " . $this->db->quoteName($decField) . " > 0 
                       THEN " . $this->db->quoteName($decField) . " - 1 
                       ELSE 0 
                  END
              WHERE " . $this->db->quoteName('id') . " = " . $idComment;

        $this->db->setQuery($query);
        $result = $this->db->execute();

        if (!$result) {
            return [
                'success' => false,
                'error' => 'Ошибка выполнения запроса',
                'likes' => 0,
                'dislikes' => 0
            ];
        }
        // Получаем обновлённые значения
        $querySelect = $this->db->getQuery(true)
            ->select($this->db->quoteName(['likes', 'dislikes']))
            ->from($this->db->quoteName('#__slt_comments'))
            ->where($this->db->quoteName('id') . ' = ' . $idComment);

        $this->db->setQuery($querySelect);
        $updatedValues = $this->db->loadObject();

        return [
            'success' => true,
            'likes' => $updatedValues->likes ?? 0,
            'dislikes' => $updatedValues->dislikes ?? 0,
            'action' => $type
        ];
    }
}