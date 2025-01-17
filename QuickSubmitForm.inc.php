<?php

/**
 * @file plugins/importexport/quickSubmit/QuickSubmitForm.inc.php
 *
 * Copyright (c) 2013-2019 Simon Fraser University
 * Copyright (c) 2003-2019 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class QuickSubmitForm
 * @ingroup plugins_importexport_quickSubmit
 *
 * @brief Form for QuickSubmit one-page submission plugin
 */


import('lib.pkp.classes.form.Form');
import('classes.submission.SubmissionMetadataFormImplementation');
import('classes.publication.Publication');

class QuickSubmitForm extends Form {
	/** @var Request */
	protected $_request;

	/** @var Submission */
	protected $_submission;

	/** @var Journal */
	protected $context;

	/** @var SubmissionMetadataFormImplementation */
	protected $_metadataFormImplem;

	/** @var PublishedArticle */
	protected $_publishedSubmission;

	/**
	 * Constructor
	 * @param $plugin object
	 * @param $request object
	 */
	function __construct($plugin, $request) {
		parent::__construct($plugin->getTemplateResource('index.tpl'));

		$this->_request = $request;
		$this->_context = $request->getContext();

		$this->_metadataFormImplem = new SubmissionMetadataFormImplementation($this);

		$locale = $request->getUserVar('locale');
		if ($locale && ($locale != AppLocale::getLocale())) {
			$this->setDefaultFormLocale($locale);
		}

		if ($submissionId = $request->getUserVar('submissionId')) {
			$submissionDao = Application::getSubmissionDAO();
			$this->_submission = $submissionDao->getById($submissionId, $this->_context->getId(), false);
			$this->_submission->setLocale($this->getDefaultFormLocale());
			$submissionDao->updateObject($this->_submission);

			$this->_metadataFormImplem->addChecks($this->_submission);
		}

		$this->addCheck(new FormValidatorPost($this));
		$this->addCheck(new FormValidatorCSRF($this));
		$this->addCheck(new FormValidatorCustom($this, 'sectionId', 'required', 'author.submit.form.sectionRequired', array(DAORegistry::getDAO('SectionDAO'), 'sectionExists'), array($this->_context->getId())));

		// Validation checks for this form
		$supportedSubmissionLocales = $this->_context->getSupportedSubmissionLocales();
		if (!is_array($supportedSubmissionLocales) || count($supportedSubmissionLocales) < 1)
			$supportedSubmissionLocales = array($this->_context->getPrimaryLocale());
		$this->addCheck(new FormValidatorInSet($this, 'locale', 'required', 'submission.submit.form.localeRequired', $supportedSubmissionLocales));

		$this->addCheck(new FormValidatorURL($this, 'licenseURL', 'optional', 'form.url.invalid'));
	}

	/**
	 * Get the submission associated with the form.
	 * @return Submission
	 */
	function getSubmission() {
		return $this->_submission;
	}

	/**
	 * Get the names of fields for which data should be localized
	 * @return array
	 */
	function getLocaleFieldNames() {
		return $this->_metadataFormImplem->getLocaleFieldNames();
	}

	/**
	 * Display the form.
	 */
	function display($request = null, $template = null) {
		$templateMgr = TemplateManager::getManager($request);

		$templateMgr->assign(
			'supportedSubmissionLocaleNames',
			$this->_context->getSupportedSubmissionLocaleNames()
		);

		// Tell the form what fields are enabled (and which of those are required)
		foreach (Application::getMetadataFields() as $field) {
			$templateMgr->assign(array(
				$field . 'Enabled' => in_array($this->_context->getData($field), array(METADATA_ENABLE, METADATA_REQUEST, METADATA_REQUIRE)),
				$field . 'Required' => $this->_context->getData($field) === METADATA_REQUIRE,
			));
		}

		// Cover image delete link action
		$locale = AppLocale::getLocale();

		import('lib.pkp.classes.linkAction.LinkAction');
		import('lib.pkp.classes.linkAction.request.AjaxModal');
		$router = $this->_request->getRouter();
		$templateMgr->assign('openCoverImageLinkAction', new LinkAction(
			'uploadFile',
			new AjaxModal(
				$router->url($this->_request, null, null, 'importexport', array('plugin', 'QuickSubmitPlugin', 'uploadCoverImage'), array(
					'coverImage' => $this->_submission->getCoverImage($locale),
					'submissionId' => $this->_submission->getId(),
					'publicationId' => $this->_submission->getCurrentPublication()->getId(),
					// This action can be performed during any stage,
					// but we have to provide a stage id to make calls
					// to IssueEntryTabHandler
					'stageId' => WORKFLOW_STAGE_ID_PRODUCTION,
				)),
				__('common.upload'),
				'modal_add_file'
			),
			__('common.upload'),
			'add'
		));

		// Get section for this context
		$sectionDao = DAORegistry::getDAO('SectionDAO');
		$sectionOptions = array('0' => '') + $sectionDao->getTitlesByContextId($this->_context->getId());
		$templateMgr->assign('sectionOptions', $sectionOptions);

		// Get published Issues
		$issueDao = DAORegistry::getDAO('IssueDAO');
		$issuesIterator = $issueDao->getIssues($this->_context->getId());
		$issues = $issuesIterator->toArray();
		$templateMgr->assign('hasIssues', count($issues) > 0);

		// Get Issues
		$templateMgr->assign(array(
			'issueOptions' => $this->getIssueOptions($this->_context),
			'submission' => $this->_submission,
			'locale' => $this->getDefaultFormLocale(),
			'publicationId' => $this->_submission->getCurrentPublication()->getId(),
		));

		$sectionDao = DAORegistry::getDAO('SectionDAO');
		$sectionId = $this->getData('sectionId') ?: $this->_submission->getSectionId();
		$section = $sectionDao->getById($sectionId, $this->_context->getId());
		$templateMgr->assign(array(
			'wordCount' => $section->getAbstractWordCount(),
			'abstractsRequired' => !$section->getAbstractsNotRequired(),
		));

		parent::display($request, $template);
	}

	/**
	 * @copydoc Form::validate
	 */
	function validate($callHooks = true) {
		if (!parent::validate($callHooks)) return false;

		// Validate Issue if Published is selected
		// if articleStatus == 1 => should have issueId
		if ($this->getData('articleStatus') == 1) {
			if ($this->getData('issueId') <= 0) {
				$this->addError('issueId', __('plugins.importexport.quickSubmit.selectIssue'));
				$this->errorFields['issueId'] = 1;

				return false;
			}
		}

		return true;

	}

	/**
	 * Initialize form data for a new form.
	 */
	function initData() {
		$this->_data = array();

		if (!$this->_submission) {
			$this->_data['locale'] = $this->getDefaultFormLocale();

			// Get Sections
			$sectionDao = DAORegistry::getDAO('SectionDAO');
			$sectionOptions = $sectionDao->getTitlesByContextId($this->_context->getId());

			// Create and insert a new submission
			$submissionDao = Application::getSubmissionDAO();
			$this->_submission = $submissionDao->newDataObject();
			$this->_submission->setContextId($this->_context->getId());
			$this->_submission->setStatus(STATUS_QUEUED);
			$this->_submission->setSubmissionProgress(1);
			$this->_submission->stampStatusModified();
			$this->_submission->setStageId(WORKFLOW_STAGE_ID_SUBMISSION);
			$this->_submission->setData('sectionId', $sectionId = current(array_keys($sectionOptions)));
			$this->_submission->setLocale($this->getDefaultFormLocale());

			// Insert the submission
			$this->_submission = Services::get('submission')->add($this->_submission, $this->_request);
			$this->setData('submissionId', $this->_submission->getId());

			$publication = new Publication();
			$publication->setData('submissionId', $this->_submission->getId());
			$publication->setData('locale', $this->getDefaultFormLocale());
			$publication->setData('language', PKPString::substr($this->getDefaultFormLocale(), 0, 2));
			$publication->setData('sectionId', $sectionId);
			$publication->setData('status', STATUS_QUEUED);
			$publication = Services::get('publication')->add($publication, $this->_request);
			$this->_submission = Services::get('submission')->edit($this->_submission, ['currentPublicationId' => $publication->getId()], $this->_request);

			$this->_metadataFormImplem->initData($this->_submission);

			// Add the user manager group (first that is found) to the stage_assignment for that submission
			$user = $this->_request->getUser();

			$userGroupAssignmentDao = DAORegistry::getDAO('UserGroupAssignmentDAO');
			$userGroupDao = DAORegistry::getDAO('UserGroupDAO');

			$userGroupId = null;
			$managerUserGroupAssignments = $userGroupAssignmentDao->getByUserId($user->getId(), $this->_context->getId(), ROLE_ID_MANAGER);
			if($managerUserGroupAssignments) {
				while($managerUserGroupAssignment = $managerUserGroupAssignments->next()) {
					$managerUserGroup = $userGroupDao->getById($managerUserGroupAssignment->getUserGroupId());
					$userGroupId = $managerUserGroup->getId();
					break;
				}
			}

			// Assign the user author to the stage
			$stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO');
			$stageAssignmentDao->build($this->_submission->getId(), $userGroupId, $user->getId());
		}
	}

	/**
	 * Assign form data to user-submitted data.
	 */
	function readInputData() {
		$this->_metadataFormImplem->readInputData();

		$this->readUserVars(
			array(
				'issueId',
				'pages',
				'datePublished',
				'licenseURL',
				'copyrightHolder',
				'copyrightYear',
				'sectionId',
				'submissionId',
				'articleStatus',
				'locale'
			)
		);
	}

	/**
	 * cancel submit
	 */
	function cancel() {
		$submissionDao = Application::getSubmissionDAO();
		$submissionDao->deleteById($this->getData('submissionId'));
	}

	/**
	 * Save settings.
	 */
	function execute() {
		// Execute submission metadata related operations.
		$this->_metadataFormImplem->execute($this->_submission, $this->_request);

		$this->_submission->setSectionId($this->getData('sectionId'));

		// articleStatus == 1 -> Published and to an Issue
		if ($this->getData('articleStatus') == 1) {
			$this->_submission->setStatus(STATUS_PUBLISHED);
			$this->_submission->setCopyrightYear($this->getData('copyrightYear'));
			$this->_submission->setCopyrightHolder($this->getData('copyrightHolder'), null);
			$this->_submission->setLicenseURL($this->getData('licenseURL'));
			$this->_submission->setPages($this->getData('pages'));

			// Insert new publishedArticle
			$publishedSubmissionDao = DAORegistry::getDAO('PublishedArticleDAO');
			$publishedSubmission = $publishedSubmissionDao->newDataObject();
			$publishedSubmission->setId($this->_submission->getId());
			$publishedSubmission->setDatePublished($this->getData('datePublished'));
			$publishedSubmission->setSequence(REALLY_BIG_NUMBER);
			$publishedSubmission->setAccessStatus(ARTICLE_ACCESS_ISSUE_DEFAULT);
			$publishedSubmission->setIssueId($this->getData('issueId'));
			$publishedSubmissionDao->insertObject($publishedSubmission);

			$this->_publishedSubmission = $publishedSubmission;
		}

		// Copy GalleyFiles to Submission Files
		// Get Galley Files by SubmissionId
		$galleyDao = DAORegistry::getDAO('ArticleGalleyDAO');
		$galleyFilesRes = $galleyDao->getByPublicationId($this->_submission->getCurrentPublication()->getId());

		if (!is_null($galleyFilesRes)) {
			$galleyFiles = $galleyFilesRes->toAssociativeArray();

			$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO'); /* @var $submissionFileDao SubmissionFileDAO */
			import('lib.pkp.classes.file.SubmissionFileManager');
			$submissionFileManager = new SubmissionFileManager($this->_context->getId(), $this->_submission->getId());

			foreach($galleyFiles as $galleyFile) {
				$newFile = $galleyFile->getFile();
				if ($newFile) {
					$revisionNumber = $submissionFileDao->getLatestRevisionNumber($newFile->getFileId());
					$submissionFileManager->copyFileToFileStage($newFile->getFileId(), $revisionNumber, SUBMISSION_FILE_SUBMISSION, null, true);
				}
			}
		}

		$this->_submission->setLocale($this->getData('locale'));
		$this->_submission->setStageId(WORKFLOW_STAGE_ID_PRODUCTION);
		$this->_submission->setDateSubmitted(Core::getCurrentDate());
		$this->_submission->setSubmissionProgress(0);

		parent::execute($this->_submission);

		$submissionDao = Application::getSubmissionDAO();
		$submissionDao->updateObject($this->_submission);

		if ($this->getData('articleStatus') == 1) {
			$publishedSubmissionDao = DAORegistry::getDAO('PublishedArticleDAO');
			$publishedSubmissionDao->resequencePublishedArticles($this->_submission->getSectionId(), $this->_publishedSubmission->getIssueId());

			// If we're using custom section ordering, and if this is the first
			// article published in a section, make sure we enter a custom ordering
			// for it. (Default at the end of the list.)
			$sectionDao = DAORegistry::getDAO('SectionDAO');
			if ($sectionDao->customSectionOrderingExists($this->_publishedSubmission->getIssueId())) {
				if ($sectionDao->getCustomSectionOrder($this->_publishedSubmission->getIssueId(), $this->_submission->getSectionId()) === null) {
					$sectionDao->insertCustomSectionOrder($this->_publishedSubmission->getIssueId(), $this->_submission->getSectionId(), REALLY_BIG_NUMBER);
					$sectionDao->resequenceCustomSectionOrders($this->_publishedSubmission->getIssueId());
				}
			}
		}

		// Index article.
		$articleSearchIndex = Application::getSubmissionSearchIndex();
		$articleSearchIndex->submissionMetadataChanged($this->_submission);
		$articleSearchIndex->submissionFilesChanged($this->_submission);
		$articleSearchIndex->submissionChangesFinished();

	}

	/**
	 * builds the issue options pulldown for published and unpublished issues
	 * @param $journal Journal
	 * @return array Associative list of options for pulldown
	 */
	function getIssueOptions($journal) {
		$issuesPublicationDates = array();
		$issueOptions = array();
		$journalId = $journal->getId();

		$issueDao = DAORegistry::getDAO('IssueDAO');

		$issueOptions[-1] =  '------    ' . __('editor.issues.futureIssues') . '    ------';
		$issueIterator = $issueDao->getUnpublishedIssues($journalId);
		while ($issue = $issueIterator->next()) {
			$issueOptions[$issue->getId()] = $issue->getIssueIdentification();
			$issuesPublicationDates[$issue->getId()] = strftime(Config::getVar('general', 'date_format_short'), strtotime(Core::getCurrentDate()));
		}
		$issueOptions[-2] = '------    ' . __('editor.issues.currentIssue') . '    ------';
		$issuesIterator = $issueDao->getPublishedIssues($journalId);
		$issues = $issuesIterator->toArray();
		if (isset($issues[0]) && $issues[0]->getCurrent()) {
			$issueOptions[$issues[0]->getId()] = $issues[0]->getIssueIdentification();
			$issuesPublicationDates[$issues[0]->getId()] = strftime(Config::getVar('general', 'date_format_short'), strtotime($issues[0]->getDatePublished()));
			array_shift($issues);
		}
		$issueOptions[-3] = '------    ' . __('editor.issues.backIssues') . '    ------';
		foreach ($issues as $issue) {
			$issueOptions[$issue->getId()] = $issue->getIssueIdentification();
			$issuesPublicationDates[$issue->getId()] = strftime(Config::getVar('general', 'date_format_short'), strtotime($issues[0]->getDatePublished()));
		}

		$templateMgr = TemplateManager::getManager($this->_request);
		$templateMgr->assign('issuesPublicationDates', json_encode($issuesPublicationDates));

		return $issueOptions;
	}
}

