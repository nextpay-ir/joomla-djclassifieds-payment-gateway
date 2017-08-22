<?php
ini_set('display_errors', 1);
defined('_JEXEC') or die('Restricted access');
jimport('joomla.event.plugin');
$lang = JFactory::getLanguage();
$lang->load('plg_djclassifiedspayment_djcfNextpay',JPATH_ADMINISTRATOR);
require_once(JPATH_BASE.DS.'administrator/components/com_djclassifieds/lib/djseo.php');
require_once(JPATH_BASE.DS.'administrator/components/com_djclassifieds/lib/djnotify.php');


class plgdjclassifiedspaymentdjcfNextpay extends JPlugin
{
	// constructor
	function plgdjclassifiedspaymentdjcfNextpay( &$subject, $config)
	{
		parent::__construct($subject, $config);
		$this->loadLanguage('plg_djcfNextpay');
		$params["plugin_name"] = "djcfNextpay";
		$params["logo"] = "nextpay_overview.png";
		$params["description"] = JText::_("PLG_DJCFNEXTPAY_PAYMENT_METHOD_DESC");
		$params["payment_method"] = JText::_("PLG_DJCFNEXTPAY_PAYMENT_METHOD_NAME");
		$params["currency_code"] = $this->params->get("currency_code");
		$params["api_key"] = $this->params->get("api_key");
		$this->params = $params;

	}


	function onProcessPayment()
	{
		$ptype = JRequest::getVar('ptype','');
		$id = JRequest::getInt('id','0');
		$html="";

		if($ptype == $this->params["plugin_name"])
		{
			$action = JRequest::getVar('pactiontype','');
			switch ($action)
			{
				case "notify" :
					$html = $this->_notify_url($id);
					break;
				case 'process':
				default :
					$html =  $this->process($id);
					break;
			}
		}
		return $html;
	}

	function process($id)
	{
		JTable::addIncludePath(JPATH_COMPONENT_ADMINISTRATOR.DS.'tables');
		jimport( 'joomla.database.table' );
		$db 	= JFactory::getDBO();
		$app 	= JFactory::getApplication();
		$par 	= JComponentHelper::getParams( 'com_djclassifieds' );
		$user 	= JFactory::getUser();
		$ptype	= JRequest::getVar('ptype'); // payment plaugin type
		$type	= JRequest::getVar('type','');
		$row 	= JTable::getInstance('Payments', 'DJClassifiedsTable');

		$remote_addr =  $_SERVER['REMOTE_ADDR'];

		if($type=='prom_top'){
			$query ="SELECT i.* FROM #__djcf_items i "
				."WHERE i.id=".$id." LIMIT 1";
			$db->setQuery($query);
			$item = $db->loadObject();
			if(!isset($item)){
				$message = JText::_('COM_DJCLASSIFIEDS_WRONG_AD');
				$redirect="index.php?option=com_djclassifieds&view=items&cid=0";
				$app->redirect(JRoute::_($redirect), $message, 'warning');
			}

			$row->item_id = $id;
			$row->user_id = $user->id;
			$row->method = $ptype;
			$row->status = 'Start';
			$row->ip_address = $remote_addr;
			$row->price = $par->get('promotion_move_top_price',0);
			$row->type=2;
			$row->store();

			$amount = $par->get('promotion_move_top_price',0);
			$itemname = $item->name;
			$payment_id = $row->id;
			$item_cid = '&cid='.$item->cat_id;
		}else if($type=='points'){
			$query ="SELECT p.* FROM #__djcf_points p "
				."WHERE p.id=".$id." LIMIT 1";
			$db->setQuery($query);
			$points = $db->loadObject();
			if(!isset($points)){
				$message = JText::_('COM_DJCLASSIFIEDS_WRONG_POINTS_PACKAGE');
				$redirect="index.php?option=com_djclassifieds&view=items&cid=0";
				$app->redirect(JRoute::_($redirect), $message, 'warning');
			}
			$row->item_id = $id;
			$row->user_id = $user->id;
			$row->method = $ptype;
			$row->status = 'Start';
			$row->ip_address = $remote_addr;
			$row->price = $points->price;
			$row->type=1;

			$row->store();

			$amount = $points->price;
			$itemname = $points->name;
			$payment_id = $row->id;
			$item_cid = '';
		}else{
			$query ="SELECT i.*, c.price as c_price FROM #__djcf_items i "
				."LEFT JOIN #__djcf_categories c ON c.id=i.cat_id "
				."WHERE i.id=".$id." LIMIT 1";
			$db->setQuery($query);
			$item = $db->loadObject();

			if(!isset($item)){
				$message = JText::_('COM_DJCLASSIFIEDS_WRONG_AD');
				$redirect="index.php?option=com_djclassifieds&view=items&cid=0";
				$app->redirect(JRoute::_($redirect), $message, 'warning');
			}

			$amount = 0;

			if(strstr($item->pay_type, 'cat')){
				$amount += $item->c_price/100;
			}
			if(strstr($item->pay_type, 'duration_renew')){
				$query = "SELECT d.price_renew FROM #__djcf_days d "
					."WHERE d.days=".$item->exp_days;
				$db->setQuery($query);
				$amount += $db->loadResult();
			}else if(strstr($item->pay_type, 'duration')){
				$query = "SELECT d.price FROM #__djcf_days d "
					."WHERE d.days=".$item->exp_days;
				$db->setQuery($query);
				$amount += $db->loadResult();
			}

			$query = "SELECT p.* FROM #__djcf_promotions p "
				."WHERE p.published=1 ORDER BY p.id ";
			$db->setQuery($query);
			$promotions=$db->loadObjectList();
			foreach($promotions as $prom){
				if(strstr($item->pay_type, $prom->name)){
					$amount += $prom->price;
				}
			}

			$row->item_id = $id;
			$row->user_id = $user->id;
			$row->method = $ptype;
			$row->status = 'Start';
			$row->ip_address = $remote_addr;
			$row->price = $amount;
			$row->type=0;

			$row->store();

			$itemname = $item->name;
			$payment_id = $row->id;
			$item_cid = '&cid='.$item->cat_id;
		}

		$api_key = $this->params['api_key'];
		$amount = $this->NextPayCheckAmount($amount);

		$payment_title = 'ItemID:'.$id.' ('.$itemname.')';
		$payment_reason = $type ? $type : $item->pay_type;

		$CallbackURL = JRoute::_(JURI::base() . 'index.php?option=com_djclassifieds&task=processPayment&ptype=djcfNextpay&pactiontype=notify&amount='.$amount);


		$params = array(
			'api_key' => $api_key,
			'amount' => $amount,
			'order_id' => $payment_id,
			'callback_uri' => $CallbackURL
		);

		try{

			$trans_id = "";
			$code_error = -1000;

			$soap_client = new SoapClient("https://api.nextpay.org/gateway/token.wsdl", array('encoding' => 'UTF-8'));
			$res = $soap_client->TokenGenerator($params);

			$res = $res->TokenGeneratorResult;

			if ($res != "" && $res != NULL && is_object($res)) {
			    if (intval($res->code) == -1){
				$trans_id = $res->trans_id;
				$app->redirect("https://api.nextpay.org/gateway/payment/".$trans_id);
			    }else{
				$code_error = $res->code;
				$error = "خطا در پاسخ دهی به درخواست با :" . $code_error;
				throw new Exception($error);
			    }
			}else{
			    $error = "خطا در پاسخ دهی به درخواست با SoapClinet";
			    throw new Exception($error);
			}

		} catch (Exception $e) {
			$return = JRoute::_('index.php/component/djclassifieds/?view=payment&id=' . $id, false);
			$message = JText::_("PLG_DJCFNEXTPAY_PAYMENT_ERROR") . $e->getMessage();
			$app->redirect($return, $message, 'error');
			exit;
		}
	}

	function _notify_url()
	{

		$db = JFactory::getDBO();
		$par = &JComponentHelper::getParams( 'com_djclassifieds' );
		// $user	= JFactory::getUser();
		$app = JFactory::getApplication();
		$input = $app->input;
		$messageUrl = JRoute::_(DJClassifiedsSEO::getCategoryRoute('0:all'));

		try{

			$amount = $input->getInt('amount', 0);
			$payment_id = $input->getInt('order_id', 0);
			$trans_id = $input->get('trans_id', 0);
			$api_key = $this->params['api_key'];

			$params = array(
				'api_key' => $api_key,
				'amount' => $amount,
				'order_id' => $payment_id,
				'trans_id' => $trans_id
			);

			$soap_client = new SoapClient("https://api.nextpay.org/gateway/verify.wsdl", array('encoding' => 'UTF-8'));
			$res = $soap_client->PaymentVerification($params);

			$res = $res->PaymentVerificationResult;
			$code = -1000;

			if ($res != "" && $res != NULL && is_object($res)) {
			    $code = $res->code;
			}


			if (intval($code) == 0)
			{
				$query = "UPDATE #__djcf_payments SET status='Completed', transaction_id='".$trans_id."' "
					."WHERE id=".$payment_id." AND method='".$this->params['plugin_name']."'";
				$db->setQuery($query);
				$db->query();

				$this->_setPaymentCompleted((int)$payment_id);

				$message = JText::_("PLG_DJCFNEXTPAY_PAYMENT_SUCCEED") . '<br>' .  JText::_("PLG_DJCFNEXTPAY_PAYMENT_REF_ID") . $trans_id;
				$app->redirect($messageUrl, $message, 'message');

				exit;
			}
			$error = "پرداخت ناموفق ، کد خطا : " . $code;

			throw new Exception($error);

		} catch (Exception $e) {
			$message = JText::_("PLG_DJCFNEXTPAY_PAYMENT_ERROR") . $e->getMessage();
			$app->redirect($messageUrl, $message, 'warning');
			exit;
		}
	}

	private function _setPaymentCompleted($id) {

		$db = JFactory::getDBO();
		$par 	= JComponentHelper::getParams( 'com_djclassifieds' );

		$query = "SELECT p.*  FROM #__djcf_payments p "
			."WHERE p.id='".$id."' ";
		$db->setQuery($query);
		$payment = $db->loadObject();

		if($payment){

			if($payment->type==2){

				$date_sort = date("Y-m-d H:i:s");
				$query = "UPDATE #__djcf_items SET date_sort='".$date_sort."' "
					."WHERE id=".$payment->item_id." ";
				$db->setQuery($query);
				$db->query();
			}else if($payment->type==1){

				$query = "SELECT p.points  FROM #__djcf_points p WHERE p.id='".$payment->item_id."' ";
				$db->setQuery($query);
				$points = $db->loadResult();

				$query = "INSERT INTO #__djcf_users_points (`user_id`,`points`,`description`) "
					."VALUES ('".$payment->user_id."','".$points."','".JText::_('COM_DJCLASSIFIEDS_POINTS_PACKAGE')." - ".$this->params['payment_method']." <br />".JText::_('COM_DJCLASSIFIEDS_PAYMENT_ID').': '.$payment->id."')";
				$db->setQuery($query);
				$db->query();
			}else{

				$query = "SELECT c.*  FROM #__djcf_items i, #__djcf_categories c "
					."WHERE i.cat_id=c.id AND i.id='".$payment->item_id."' ";
				$db->setQuery($query);
				$cat = $db->loadObject();

				$pub=0;
				if(($cat->autopublish=='1') || ($cat->autopublish=='0' && $par->get('autopublish')=='1')){
					$pub = 1;
				}

				$query = "UPDATE #__djcf_items SET payed=1, pay_type='', published='".$pub."' "
					."WHERE id=".$payment->item_id." ";
				$db->setQuery($query);
				$db->query();
			}

		}

	}


	private function NextPayCheckAmount($amount)
	{
		$currency = $this->params['currency_code'];
		if(!(bool)$currency){// currency_code == 0 => rial
			$amount = $amount / 10;
		}
		return (int)$amount;
	}


	/*
	 * when payment will be listed in payment choose page
	 */
	function onPaymentMethodList($val)
	{
		$type='';
		if($val['type']){
			$type='&type='.$val['type'];
		}
		$html ='';
		if($this->params["api_key"] != ''){
			$payText =  JText::_("PLG_DJCFNEXTPAY_PAYMENT_PAY");
			$paymentLogoPath = JURI::root()."plugins/djclassifiedspayment/".$this->params["plugin_name"]."/".$this->params["plugin_name"]."/images/".$this->params["logo"];
			$form_action = JURI::root()."index.php?option=com_djclassifieds&task=processPayment&ptype=".$this->params["plugin_name"]."&pactiontype=process&id=".$val["id"].$type;
			$html ='<table cellpadding="5" cellspacing="0" width="100%" border="0">
				<tr>';
					if($this->params["logo"] != ""){
				$html .='<td class="td1" width="160" align="center">
						<img src="'.$paymentLogoPath.'" title="'. $this->params["payment_method"].'"/>
					</td>';
					 }
					$html .='<td class="td2">
						<h2>' . $this->params["payment_method"] . '</h2>
						<p style="text-align:justify;">'.$this->params["description"].'</p>
					</td>
					<td class="td3" width="130" align="center">
						<a class="button" style="text-decoration:none;" href="'.$form_action.'">'. $payText .'</a>
					</td>
				</tr>
			</table>';
		}
		return $html;
	}
}
