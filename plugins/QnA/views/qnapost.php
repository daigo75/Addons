<?php if (!defined('APPLICATION')) exit();

//var_dump($this->UserCanPostDiscussion, $this->UserCanPostQuestion);

?>
<div class="QnA-Post">
	<div class="P YesScript">
		 <?php echo T('You can either ask a question or start a discussion.', 'You can either ask a question or start a discussion. Choose what you want to do below.'); ?>
	</div>
	<style>.NoScript { display: none; }</style>
	<noscript>
		 <style>.NoScript { display: block; } .YesScript { display: none; }</style>
	</noscript>
	<div class="P NoScript">
		 <?php echo $Form->RadioList('Type', array('Question' => T('Ask a Question'), 'Discussion' => T('Start a New Discussion'))); ?>
	</div>
	<div class="YesScript">
		 <div class="Tabs">
				<ul>
					<?php
						if($this->UserCanPostFreely || $this->UserCanPostQuestion) {
							$CssClass = $Form->GetValue('Type') == 'Question' ? 'Active' : '';
							$QuestionLink = Anchor(T('Ask a Question'),
																		 '#',
																		 'QnAButton TabLink',
																		 array('id' => 'QnA_Question',
																					 'rel' => 'Question',
																					 )
																		 );

							echo Wrap($QuestionLink,
												'li',
												array('class' => $CssClass)
											 );
						}

						if($this->UserCanPostFreely || $this->UserCanPostDiscussion) {
							$CssClass = $Form->GetValue('Type') == 'Discussion' ? 'Active' : '';
							$QuestionLink = Anchor(T('Start a New Discussion'),
																		 '#',
																		 'QnAButton TabLink',
																		 array('id' => 'QnA_Discussion',
																					 'rel' => 'Discussion',
																					 )
																		 );
							echo Wrap($QuestionLink,
												'li',
												array('class' => $CssClass)
											 );
						}


					?>
					<!--<li class="<?php echo $Form->GetValue('Type') == 'Question' ? 'Active' : '' ?>"><a id="QnA_Question" class="QnAButton TabLink" rel="Question" href="#"><?php echo T('Ask a Question'); ?></a></li>-->
					<!--<li class="<?php echo $Form->GetValue('Type') == 'Discussion' ? 'Active' : '' ?>"><a id="QnA_Discussion" class="QnAButton TabLink" rel="Discussion" href="#"><?php echo T('Start a New Discussion'); ?></a></li>-->
				</ul>
		 </div>
	</div>
</div>
