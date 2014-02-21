<?php if (!defined('APPLICATION')) exit();
/**
 * @copyright Copyright 2008, 2009 Vanilla Forums Inc.
 * @license http://www.opensource.org/licenses/gpl-2.0.php GPLv2
 */

// Define the plugin:
$PluginInfo['QnA'] = array(
	'Name' => 'Q&A',
	'Description' => 'Users may designate a discussion as a Question and then officially accept one or more of the comments as the answer.',
	'Version' => '14.02.21.001',
	'RequiredApplications' => array('Vanilla' => '2.0.18'),
	'MobileFriendly' => TRUE,
	'Author' => 'Diego Zanella (originally Todd Burry)',
	'AuthorEmail' => 'todd@vanillaforums.com',
	'AuthorUrl' => 'http://thankfrank.com',
	'RegisterPermissions' => array(
		'Plugins.QnA.CanPostQuestion',
		'Plugins.QnA.CanPostDiscussion',
		'Plugins.QnA.CanPostFreely',
	),
);

/**
 * Adds Question & Answer format to Vanilla.
 *
 * You can set Plugins.QnA.UseBigButtons = TRUE in config to separate 'New Discussion'
 * and 'Ask Question' into "separate" forms each with own big button in Panel.
 */
class QnAPlugin extends Gdn_Plugin {
	const DEFAULT_PERMISSION_CATEGORY_ID = -1;
	const ACTIVITY_ANSWER_POSTED = 'QuestionAnswer';
	const ACTIVITY_ANSWER_ACCEPTED = 'AnswerAccepted';

	public function __construct() {
		parent::__construct();
	}

	public function Setup() {
		$this->Structure();

		// Register the permissions associating them to the Categories
		Gdn::PermissionModel()->Define(array('Plugins.QnA.CanPostQuestion',
																				 'Plugins.QnA.CanPostDiscussion',
																				 'Plugins.QnA.CanPostFreely',),
																	 'tinyint',
																	 'Category',
																	 'PermissionCategoryID');

		// Create Route to redirect calls to /discussion to /qnadiscussion
		Gdn::Router()->SetRoute('^post/discussion(/.*)?$',
														'post/qnadiscussion$1',
														'Internal');
		// Create Route to redirect calls to /editdiscussion to /qnaeditdiscussion
		Gdn::Router()->SetRoute('^./*?post/editdiscussion(/.*)?$',
														'post/qnaeditdiscussion$1',
														'Internal');
		// Create Route to redirect calls to index and default method to /qnadiscussion
		Gdn::Router()->SetRoute('^post(/index)?(/)?$',
														'post/qnadiscussion$1',
														'Internal');

		// TODO Find a way to handle the condition in which /post/"whatever" is called. Such call redirects to the index, but it's not intercepted by the routes and bring the user to a broken page, since posting permissions are not checked
	}

	/**
	 * Cleanup operations to be performend when the Plugin is disabled, but not
	 * permanently removed.
	 */
	public function OnDisable() {
		// Remove the Routes created by the Plugin.
		Gdn::Router()->DeleteRoute('^post/discussion(/.*)?$');
		Gdn::Router()->DeleteRoute('^post/editdiscussion(/.*)?$');
	}

	public function Structure() {
		Gdn::Structure()
			->Table('Discussion');

		$QnAExists = Gdn::Structure()->ColumnExists('QnA');
		$DateAcceptedExists = Gdn::Structure()->ColumnExists('DateAccepted');

		Gdn::Structure()
			->Column('QnA', array('Unanswered', 'Answered', 'Accepted', 'Rejected'), NULL)
			->Column('DateAccepted', 'datetime', TRUE) // The
			->Column('DateOfAnswer', 'datetime', TRUE) // The time to answer an accepted question.
			->Set();

		Gdn::Structure()
			->Table('Comment')
			->Column('QnA', array('Accepted', 'Rejected'), NULL)
			->Column('DateAccepted', 'datetime', TRUE)
			->Column('AcceptedUserID', 'int', TRUE)
			->Set();

		Gdn::SQL()->Replace(
			'ActivityType',
			array('AllowComments' => '0', 'RouteCode' => 'question', 'Notify' => '1', 'Public' => '0', 'ProfileHeadline' => '', 'FullHeadline' => ''),
			array('Name' => self::ACTIVITY_ANSWER_POSTED), TRUE);
		Gdn::SQL()->Replace(
			'ActivityType',
			array('AllowComments' => '0', 'RouteCode' => 'answer', 'Notify' => '1', 'Public' => '0', 'ProfileHeadline' => '', 'FullHeadline' => ''),
			array('Name' => self::ACTIVITY_ANSWER_ACCEPTED), TRUE);

		if ($QnAExists && !$DateAcceptedExists) {
			// Default the date accepted to the accepted answer's date.
			$Px = Gdn::Database()->DatabasePrefix;
			$Sql = "update {$Px}Discussion d set DateAccepted = (select min(c.DateInserted) from {$Px}Comment c where c.DiscussionID = d.DiscussionID and c.QnA = 'Accepted')";
			Gdn::SQL()->Query($Sql, 'update');
			Gdn::SQL()->Update('Discussion')
				->Set('DateOfAnswer', 'DateAccepted', FALSE, FALSE)
				->Put();

			Gdn::SQL()->Update('Comment c')
				->Join('Discussion d', 'c.CommentID = d.DiscussionID')
				->Set('c.DateAccepted', 'c.DateInserted', FALSE, FALSE)
				->Set('c.AcceptedUserID', 'd.InsertUserID', FALSE, FALSE)
				->Where('c.QnA', 'Accepted')
				->Where('c.DateAccepted', NULL)
				->Put();
		}
	}


	/// EVENTS ///
	/**
	  * Implemented for procedo.ie.
	  * Adds a link to the User Menu that points to the page containing User's
	  * Answered questions. Link is not displayed if there are no answered
	  * questions.
	  *
	  * @param ModuleMenu Sender The menu where the link has to be added. Its
	  * HtmlID must be "UserMenu".
	  */
	 public function MenuModule_BeforeToString_Handler($Sender) {
		if($Sender->HtmlId == 'UserMenu') {
			if($this->UserAnsweredQuestions(Gdn::Session()->UserID) > 0) {
				$Sender->AddLink('QnA',
													T('My Questions'),
													'/discussions/mine?qna=Answered',
													array('Garden.SignIn.Allow'),
													array('class' => 'UserNotifications'));
			}
		}
	 }

	public function Base_BeforeCommentDisplay_Handler($Sender, $Args) {
		$QnA = GetValueR('Comment.QnA', $Args);

		if ($QnA && isset($Args['CssClass'])) {
			$Args['CssClass'] = ConcatSep(' ', $Args['CssClass'], "QnA-Item-$QnA");
		}
	}

	/**
	 *
	 * @param Gdn_Controller $Sender
	 * @param array $Args
	 */
	public function Base_CommentOptions_Handler($Sender, $Args) {
		$Discussion = GetValue('Discussion', $Args);
		$Comment = GetValue('Comment', $Args);

		if (!$Comment)
			return;

		$CommentID = GetValue('CommentID', $Comment);
		if (!is_numeric($CommentID))
			return;

		if (!$Discussion) {
			static $DiscussionModel = NULL;
			if ($DiscussionModel === NULL)
				$DiscussionModel = new DiscussionModel();
			$Discussion = $DiscussionModel->GetID(GetValue('DiscussionID', $Comment));
		}

		if (!$Discussion || strtolower(GetValue('Type', $Discussion)) != 'question')
			return;

		// Check permissions.
		$CanAccept = Gdn::Session()->CheckPermission('Garden.Moderation.Manage');
		$CanAccept |= Gdn::Session()->UserID == GetValue('InsertUserID', $Discussion) && Gdn::Session()->UserID != GetValue('InsertUserID', $Comment);

		if (!$CanAccept)
			return;

		$QnA = GetValue('QnA', $Comment);
		if ($QnA)
			return;

		// Write the links.
		$Query = http_build_query(array('commentid' => $CommentID, 'tkey' => Gdn::Session()->TransientKey()));

		echo ' <span class="MItem">'.Anchor(T('Accept', 'Accept'), '/discussion/qna/accept?'.$Query, array('class' => 'QnA-Yes LargeButton', 'title' => T('Accept this answer.'))).'</span> '.
			' <span class="MItem">'.Anchor(T('Reject', 'Reject'), '/discussion/qna/reject?'.$Query, array('class' => 'QnA-No LargeButton', 'title' => T('Reject this answer.'))).'</span> ';

		static $InformMessage = TRUE;

		if ($InformMessage && Gdn::Session()->UserID == GetValue('InsertUserID', $Discussion) && in_array(GetValue('QnA', $Discussion), array('', 'Answered'))) {
			$Sender->InformMessage(T('Click accept or reject beside an answer.'), 'Dismissable');
			$InformMessage = FALSE;
		}
	}

	public function Base_CommentInfo_Handler($Sender, $Args) {
		$Type = GetValue('Type', $Args);
		if ($Type != 'Comment')
			return;

		$QnA = GetValueR('Comment.QnA', $Args);

		if ($QnA && ($QnA == 'Accepted' || Gdn::Session()->CheckPermission('Garden.Moderation.Manage'))) {
			$Title = T("QnA $QnA Answer", "$QnA Answer");
			echo ' <span class="Tag QnA-Box QnA-'.$QnA.'" title="'.htmlspecialchars($Title).'"><span>'.$Title.'</span></span> ';
		}
	}

	public function CommentModel_BeforeNotification_Handler($Sender, $Args) {
		$ActivityModel = $Args['ActivityModel'];
		$Comment = (array)$Args['Comment'];
		$CommentID = $Comment['CommentID'];
		$Discussion = (array)$Args['Discussion'];

		if ($Comment['InsertUserID'] == $Discussion['InsertUserID'])
			return;
		if (strtolower($Discussion['Type']) != 'question')
			return;

		$ActivityID = $ActivityModel->Add(
			$Comment['InsertUserID'],
			self::ACTIVITY_ANSWER_POSTED,
			Anchor(Gdn_Format::Text($Discussion['Name']), "discussion/comment/$CommentID/#Comment_$CommentID"),
			$Discussion['InsertUserID'],
			'',
			"/discussion/comment/$CommentID/#Comment_$CommentID");
		$ActivityModel->QueueNotification($ActivityID, '', 'first');
	}

	/**
	 * @param CommentModel $Sender
	 * @param array $Args
	 */
	public function CommentModel_BeforeUpdateCommentCount_Handler($Sender, $Args) {
		$Discussion =& $Args['Discussion'];

		// Mark the question as answered.
		if (strtolower($Discussion['Type']) == 'question' && !$Discussion['Sink'] && !in_array($Discussion['QnA'], array('Answered', 'Accepted')) && $Discussion['InsertUserID'] != Gdn::Session()->UserID) {
			$Sender->SQL->Set('QnA', 'Answered');
		}
	}

	/**
	 *
	 * @param DiscussionController $Sender
	 * @param array $Args
	 */
	public function DiscussionController_QnA_Create($Sender, $Args = array()) {
		$Comment = Gdn::SQL()->GetWhere('Comment', array('CommentID' => $Sender->Request->Get('commentid')))->FirstRow(DATASET_TYPE_ARRAY);
		if (!$Comment)
			throw NotFoundException('Comment');

		$Discussion = Gdn::SQL()->GetWhere('Discussion', array('DiscussionID' => $Comment['DiscussionID']))->FirstRow(DATASET_TYPE_ARRAY);

		// Check for permission.
		if (!(Gdn::Session()->UserID == GetValue('InsertUserID', $Discussion) || Gdn::Session()->CheckPermission('Garden.Moderation.Manage'))) {
			throw PermissionException('Garden.Moderation.Manage');
		}
		if (!Gdn::Session()->ValidateTransientKey($Sender->Request->Get('tkey')))
			throw PermissionException();

		switch ($Args[0]) {
			case 'accept':
				$QnA = 'Accepted';
				break;
			case 'reject':
				$QnA = 'Rejected';
				break;
		}

		if (isset($QnA)) {
			$DiscussionSet = array('QnA' => $QnA);
			$CommentSet = array('QnA' => $QnA);

			if ($QnA == 'Accepted') {
				$CommentSet['DateAccepted'] = Gdn_Format::ToDateTime();
				$CommentSet['AcceptedUserID'] = Gdn::Session()->UserID;

				if (!$Discussion['DateAccepted']) {
					$DiscussionSet['DateAccepted'] = Gdn_Format::ToDateTime();
					$DiscussionSet['DateOfAnswer'] = $Comment['DateInserted'];
				}
			}

			// Update the comment.
			Gdn::SQL()->Put('Comment', $CommentSet, array('CommentID' => $Comment['CommentID']));

			// Update the discussion.
			if ($Discussion['QnA'] != $QnA && (!$Discussion['QnA'] || in_array($Discussion['QnA'], array('Unanswered', 'Answered', 'Rejected'))))
				Gdn::SQL()->Put(
					'Discussion',
					$DiscussionSet,
					array('DiscussionID' => $Comment['DiscussionID']));

			// Record the activity.
			if ($QnA == 'Accepted') {
				AddActivity(
					Gdn::Session()->UserID,
					self::ACTIVITY_ANSWER_ACCEPTED,
					Anchor(Gdn_Format::Text($Discussion['Name']), "/discussion/{$Discussion['DiscussionID']}/".Gdn_Format::Url($Discussion['Name'])),
					$Comment['InsertUserID'],
					"/discussion/comment/{$Comment['CommentID']}/#Comment_{$Comment['CommentID']}",
					TRUE
				);
			}
		}

		Redirect("/discussion/comment/{$Comment['CommentID']}#Comment_{$Comment['CommentID']}");
	}

	public function DiscussionModel_BeforeGet_Handler($Sender, $Args) {
		$Unanswered = Gdn::Controller()->ClassName == 'DiscussionsController' && Gdn::Controller()->RequestMethod == 'unanswered';

		if ($Unanswered) {
			$Args['Wheres']['Type'] = 'Question';
			$Sender->SQL->WhereIn('d.QnA', array('Unanswered', 'Rejected'));
		} elseif ($QnA = Gdn::Request()->Get('qna')) {
			$Args['Wheres']['QnA'] = $QnA;
		}
	}

	/**
	 * ProfileController_AfterPreferencesDefined Event Handler.
	 * Adds notification options to User's Preferences screen.
	 *
	 * @param Gdn_Controller Sender Sending controller instance.
	 */
	public function ProfileController_AfterPreferencesDefined_Handler($Sender) {
		$NotifyOfAnswerPostedMessage = T('Notify me when somebody answers my questions.');
		$NotifyOfAnswerAcceptedMessage = T('Notify me when my answers are accepted.');

		$Sender->Preferences['Notifications']['Email.' . self::ACTIVITY_ANSWER_POSTED] = $NotifyOfAnswerPostedMessage;
		$Sender->Preferences['Notifications']['Popup.' . self::ACTIVITY_ANSWER_POSTED] = $NotifyOfAnswerPostedMessage;
		$Sender->Preferences['Notifications']['Email.' . self::ACTIVITY_ANSWER_ACCEPTED] = $NotifyOfAnswerAcceptedMessage;
		$Sender->Preferences['Notifications']['Popup.' . self::ACTIVITY_ANSWER_ACCEPTED] = $NotifyOfAnswerAcceptedMessage;
	}

	/**
	 *
	 * @param DiscussionModel $Sender
	 * @param array $Args
	 */
	public function DiscussionModel_BeforeSaveDiscussion_Handler($Sender, $Args) {
//		$Sender->Validation->ApplyRule('Type', 'Required', T('Choose whether you want to ask a question or start a discussion.'));

		$Post =& $Args['FormPostValues'];
		if ($Args['Insert'] && GetValue('Type', $Post) == 'Question') {
			$Post['QnA'] = 'Unanswered';
		}
	}

	/* New Html method of adding to discussion filters */
	public function DiscussionsController_AfterDiscussionFilters_Handler($Sender) {
		$Count = Gdn::Cache()->Get('QnA-UnansweredCount');
		if ($Count === Gdn_Cache::CACHEOP_FAILURE)
			$Count = ' <span class="Aside"><span class="Popin Count" rel="/discussions/unansweredcount"></span>';
		else
			$Count = ' <span class="Aside"><span class="Count">'.$Count.'</span></span>';

		echo '<li class="QnA-UnansweredQuestions '.($Sender->RequestMethod == 'unanswered' ? ' Active' : '').'">'
			.Anchor(Sprite('SpUnansweredQuestions').T('Unanswered'), '/discussions/unanswered', 'UnansweredQuestions')
			.$Count
		.'</li>';
	}

	/* Old Html method of adding to discussion filters */
	public function DiscussionsController_AfterDiscussionTabs_Handler($Sender, $Args) {
		if (StringEndsWith(Gdn::Request()->Path(), '/unanswered', TRUE))
			$CssClass = ' class="Active"';
		else
			$CssClass = '';

		$Count = Gdn::Cache()->Get('QnA-UnansweredCount');
		if ($Count === Gdn_Cache::CACHEOP_FAILURE)
			$Count = ' <span class="Popin Count" rel="/discussions/unansweredcount">';
		else
			$Count = ' <span class="Count">'.$Count.'</span>';

		echo '<li'.$CssClass.'><a class="TabLink QnA-UnansweredQuestions" href="'.Url('/discussions/unanswered').'">'.T('Unanswered Questions', 'Unanswered').$Count.'</span></a></li>';
	}

	/**
	 * @param DiscussionsController $Sender
	 * @param array $Args
	 */
	public function DiscussionsController_Unanswered_Create($Sender, $Args = array()) {
		$Sender->View = 'Index';
		$Sender->SetData('_PagerUrl', 'discussions/unanswered/{Page}');
		$Sender->Index(GetValue(0, $Args, 'p1'));
		$this->InUnanswered = TRUE;
	}

	/**
	 * Event handler invoked before any "Discussion" page is rendered.
	 *
	 * @param DiscussionsController $Sender
	 * @param type $Args
	 */
	public function DiscussionsController_Render_Before($Sender, $Args) {
		if (strcasecmp($Sender->RequestMethod, 'unanswered') == 0) {
			$Sender->SetData('CountDiscussions', FALSE);
		}
		// Add 'Ask a Question' button if using BigButtons.
		if (C('Plugins.QnA.UseBigButtons')) {
			$QuestionModule = new NewQuestionModule($Sender, 'plugins/QnA');
			$Sender->AddModule($QuestionModule);
		}

		if (isset($this->InUnanswered)) {
			// Remove announcements that aren't questions...
			$Announcements = $Sender->Data('Announcements');
			foreach ($Announcements as $i => $Row) {
				if (GetValue('Type', $Row) != 'Questions')
					unset($Announcements[$i]);
			}
			$Sender->SetData('Announcements', array_values($Announcements));
		}
	}

	 /**
	 * @param DiscussionsController $Sender
	 * @param array $Args
	 */
	public function DiscussionsController_UnansweredCount_Create($Sender, $Args = array()) {
		Gdn::SQL()->WhereIn('QnA', array('Unanswered', 'Rejected'));
		$Count = Gdn::SQL()->GetCount('Discussion', array('Type' => 'Question'));
		Gdn::Cache()->Store('QnA-UnansweredCount', $Count, array(Gdn_Cache::FEATURE_EXPIRY => 15 * 60));

		$Sender->SetData('UnansweredCount', $Count);
		$Sender->SetData('_Value', $Count);
		$Sender->Render('Value', 'Utility', 'Dashboard');
	}

	/**
	 * Renders the discussion status (Question, Answered, Discussion) next to
	 * the discussion title.
	 *
	 * @param Gdn_Controller Sender
	 * @param array Args The arguments passed with the event.
	 */
	protected function RenderDiscussionStatus($Sender, $Args) {
		$Discussion = $Args['Discussion'];
		if (strtolower(GetValue('Type', $Discussion)) != 'question') {
			return;
		}

		$QnA = GetValue('QnA', $Discussion);
		$Title = '';
		switch ($QnA) {
			case '':
			case 'Unanswered':
			case 'Rejected':
				$Text = 'Question';
				$QnA = 'Question';
				break;
			case 'Answered':
				$Text = 'Answered';
				if (GetValue('InsertUserID', $Discussion) == Gdn::Session()->UserID) {
					$QnA = 'Answered';
					$Title = ' title="'.T("Someone's answered your question. You need to accept/reject the answer.").'"';
				}
				break;
			case 'Accepted':
				$Text = 'Answered';
				$Title = ' title="'.T("This question's answer has been accepted.").'"';
				break;
			default:
				$QnA = FALSE;
		}
		if ($QnA) {
			echo ' <span class="Tag QnA-Tag-'.$QnA.'"'.$Title.'>'.T("Q&A $QnA", $Text).'</span> ';
		}
	}

	public function Base_AfterDiscussionTitle_Handler($Sender, $Args) {
		$this->RenderDiscussionStatus($Sender, $Args);
	}

	 /**
	  * Returns a count of User's Questions that received answers.
	  *
	  * @return int The amount of User's Questions that received answers.
	  */
	 private function UserAnsweredQuestions($UserID, array $ExtraWheres = array()) {
		// TODO Make the "1 week" parameter configurable
		// Search only Questions that have Answers and that are at least one week old
		$ReferenceDate = date('Ymd', strtotime('-1 week'));
		$DefaultWheres = array(
			'DateInserted <=' => $ReferenceDate,
			'Type' => 'Question',
			'InsertUserID' => $UserID,
			'QnA' => 'Answered',
		);

		// Merge the extra WHERE clauses to the default ones
		$Wheres = array_merge($ExtraWheres,
													$DefaultWheres);

		// Check to see if the user has answered questions.
		return Gdn::SQL()
			->GetCount('Discussion',
								 $Wheres);
	 }

   /**
    * @param Gdn_Controller $Sender
    * @param array $Args
    */
   public function NotificationsController_BeforeInformNotifications_Handler($Sender, $Args) {
      $Path = trim($Sender->Request->GetValue('Path'), '/');
      if (preg_match('`^(vanilla/)?discussion[^s]`i', $Path)) {
         return;
      }

			$Count = $this->UserAnsweredQuestions(Gdn::Session()->UserID);
      if ($Count > 0) {
         $Sender->InformMessage(FormatString(T("You've asked questions that have now been answered", "<a href=\"{/discussions/mine?qna=Answered,url}\">You've asked questions that now have answers</a>. Make sure you accept/reject the answers.")), 'Dismissable');
      }
   }

	/**
	 * @param Gdn_Controller $Sender
	 * @param array $Args
	 */
	public function PostController_BeforeFormInputs_Handler($Sender, $Args) {
		$Sender->AddDefinition('QuestionTitle', T('Title'));
		$Sender->AddDefinition('DiscussionTitle', T('Title'));
		$Sender->AddDefinition('QuestionButton', T('Ask Question'));
		$Sender->AddDefinition('DiscussionButton', T('Post Discussion'));
		$Sender->AddJsFile('qna.js', 'plugins/QnA');

		$Form = $Sender->Form;
		$QuestionButton = !C('Plugins.QnA.UseBigButtons') || GetValue('Type', $_GET) == 'Question';
		if ($Sender->Form->GetValue('Type') == 'Question' && $QuestionButton) {
			Gdn::Locale()->SetTranslation('Discussion Title', T('Title'));
			Gdn::Locale()->SetTranslation('Post Discussion', T('Ask Question'));
		}

		if (!C('Plugins.QnA.UseBigButtons'))
			include $Sender->FetchViewLocation('QnAPost', 'post', 'plugins/QnA');
	}

	public function PostController_Render_Before($Sender, $Args) {
		$this->LoadUserPostingPermissions($Sender, $CategoryID);

		$Form = $Sender->Form; //new Gdn_Form();
		$QuestionButton = !C('Plugins.QnA.UseBigButtons') || GetValue('Type', $_GET) == 'Question';
		if (!$Form->IsPostBack()) {
			if (!property_exists($Sender, 'Discussion')) {
				$Form->SetValue('Type', 'Question');
			} elseif (!$Form->GetValue('Type')) {
				$Form->SetValue('Type', 'Discussion');
			}
		}

		if ($this->UserCanPostQuestion &&
				($Form->GetValue('Type') == 'Question')
				&& $QuestionButton) {
			$Sender->SetData('Title', T('Ask a Question'));
		}
		else {
			$Sender->SetData('Title', T('Start a new Discussion'));
		}
	}

	/**
	 * Add 'Ask a Question' button if using BigButtons.
	 */
	public function CategoriesController_Render_Before($Sender) {
		if (C('Plugins.QnA.UseBigButtons')) {
			$QuestionModule = new NewQuestionModule($Sender, 'plugins/QnA');
			$Sender->AddModule($QuestionModule);
		}
	}

	/**
	 * Add 'Ask a Question' button if using BigButtons.
	 */
	public function DiscussionController_Render_Before($Sender) {
		if (C('Plugins.QnA.UseBigButtons')) {
			$QuestionModule = new NewQuestionModule($Sender, 'plugins/QnA');
			$Sender->AddModule($QuestionModule);
		}
	}

	/**
	 * Add the "new question" button after the new discussion button.
	 */
	public function Base_BeforeNewDiscussionButton_Handler($Sender) {
		$NewDiscussionModule = &$Sender->EventArguments['NewDiscussionModule'];
		$NewDiscussionModule->AddButton(T('New Question'), 'post/discussion?Type=Question');
	}

	/**
	 * Checks if the User has a permission in a specific Category.
	 *
	 * @param string PermissionName The name of the Permission.
	 * @param int CategoryID The ID of the Category.
	 * @return bool True, if User has the specified permission for the Category,
	 * False otherwise.
	 */
	private function CheckCategoryPermission($PermissionName, $CategoryID) {
		if($CategoryID > 0) {
			return Gdn::Session()->CheckPermission($PermissionName,
																						 false,
																						 'Category',
																						 $CategoryID);
		}
		else {
			return Gdn::Session()->CheckPermission($PermissionName, false);
		}
	}

	/**
	 * Return the Category ID to be used to check permissions. It can be different
	 * from the Category ID, because, if Category doesn't have specific permissions,
	 * the default ones (i.e. the ones from Category ID "-1") are taken.
	 *
	 * @param int CategoryID The ID of the Category for which to retrieve the
	 * ID to be used to check the Permissions.
	 * @return int The Category ID to be used to check the Permissions.
	 */
	private function GetPermissionCategoryID($CategoryID) {
		if(empty($CategoryID)) {
			return self::DEFAULT_PERMISSION_CATEGORY_ID;
		}

		return GetValue('PermissionCategoryID', CategoryModel::Categories($CategoryID));
	}

	protected function LoadUserPostingPermissions($Sender, $CategoryID) {
		$PermissionCategoryID = $this->GetPermissionCategoryID($CategoryID);

		// Retrieve the permissions for current category
		$this->UserCanPostFreely = $this->CheckCategoryPermission('Plugins.QnA.CanPostFreely',
																															$PermissionCategoryID);
		$this->UserCanPostDiscussion = $this->CheckCategoryPermission('Plugins.QnA.CanPostDiscussion',
																																	$PermissionCategoryID);
		$this->UserCanPostQuestion = $this->CheckCategoryPermission('Plugins.QnA.CanPostQuestion',
																																$PermissionCategoryID);

		$Sender->AddDefinition('QnA_CategoryID', (int)$this->CategoryID);
		$Sender->AddDefinition('QnA_UserCanPostFreely', (int)$this->UserCanPostFreely);
		$Sender->AddDefinition('QnA_UserCanPostDiscussion', (int)$this->UserCanPostDiscussion);
		$Sender->AddDefinition('QnA_UserCanPostQuestion', (int)$this->UserCanPostQuestion);
	}

	/**
	 * Validates the discussion type to see if it's allowed to be posted in a
	 * specific category.
	 *
   * @param Gdn_Controller Sender Sending Controller instance.
   */
	private function _ValidateDiscussionType($Sender) {
		//$PostType = $Sender->Form->GetFormValue('Type');
		//$CategoryID = $Sender->Form->GetFormValue('CategoryID');
		//
		//$this->LoadUserPostingPermissions($Sender, $CategoryID);
		//
		//// Check if User can post a Question
		//if(($PostType == 'Question') &&
		//	 !($this->UserCanPostQuestion || $this->UserCanPostFreely)) {
		//	$Sender->Form->AddError(T('You are not allowed to post a Question in this Category.'));
		//}
		//else {
		//	// Check if User can post a Discussion
		//	if(!($this->UserCanPostDiscussion || $this->UserCanPostFreely)) {
		//		$Sender->Form->AddError(T('You are not allowed to post a Discussion in this Category.'));
		//	}
		//}
	}

  /**
   * Create or update a discussion.
   *
   * @param Gdn_Controller Sender Sending Controller instance.
   * @param int $CategoryID Unique ID of the category to add the discussion to.
   */
  public function PostController_QnaDiscussion_Create($Sender, $Args) {
		$CategoryID = array_shift($Args);
		// Add CategoryID as a Sender property to make it available during rendering
		$this->CategoryID = $CategoryID;

		$DiscussionModel = new DiscussionModel();

		// Set the model on the form
		$Sender->Form->SetModel($DiscussionModel);
		if($Sender->Form->AuthenticatedPostBack() === false) {
			// Just render page
		}
		else {
			$this->_ValidateDiscussionType($Sender);
		}

		$Sender->View = 'discussion';
		$Sender->Discussion($CategoryID);
	}

	public function PostController_QnaEditDiscussion_Create($Sender, $Args) {
		$DiscussionID = array_shift($Args);
		$DraftID = array_shift($DraftID);

		$DiscussionModel = new DiscussionModel();
		// Set the model on the form
		$Sender->Form->SetModel($DiscussionModel);
		if($Sender->Form->AuthenticatedPostBack() === false) {
			// Just render page
		}
		else {
			$this->_ValidateDiscussionType($Sender);
		}

		$Sender->View = 'discussion';
		$Sender->EditDiscussion($DiscussionID, $DraftID);
	}
}
