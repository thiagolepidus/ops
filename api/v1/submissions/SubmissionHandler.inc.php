<?php

/**
 * @file api/v1/submissions/PKPSubmissionHandler.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SubmissionHandler
 * @ingroup api_v1_submission
 *
 * @brief Handle API requests for submission operations.
 *
 */

use APP\core\Services;
use APP\facades\Repo;

use PKP\db\DAORegistry;
use PKP\security\Role;
use PKP\services\PKPSchemaService;

import('lib.pkp.api.v1.submissions.PKPSubmissionHandler');

class SubmissionHandler extends PKPSubmissionHandler
{
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->requiresSubmissionAccess[] = 'relatePublication';
        $this->requiresProductionStageAccess[] = 'relatePublication';
        $this->productionStageAccessRoles[] = Role::ROLE_ID_AUTHOR;
        parent::__construct();
    }

    /**
     * Modify submission endpoints
     */
    public function setupEndpoints()
    {

        // Add endpoints
        $this->_endpoints['PUT'][] = [
            'pattern' => $this->getEndpointPattern() . '/{submissionId:\d+}/publications/{publicationId:\d+}/relate',
            'handler' => [$this, 'relatePublication'],
            'roles' => [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT, Role::ROLE_ID_AUTHOR],
        ];

        // Allow authors to create and publish versions
        $this->_endpoints['POST'] = array_map(function ($endpoint) {
            if (in_array($endpoint['handler'][1], ['addPublication', 'versionPublication'])) {
                $endpoint['roles'][] = Role::ROLE_ID_AUTHOR;
            }
            return $endpoint;
        }, $this->_endpoints['POST']);
        $this->_endpoints['PUT'] = array_map(function ($endpoint) {
            if (in_array($endpoint['handler'][1], ['publishPublication', 'unpublishPublication'])) {
                $endpoint['roles'][] = Role::ROLE_ID_AUTHOR;
            }
            return $endpoint;
        }, $this->_endpoints['PUT']);

        parent::setupEndpoints();
    }

    /**
     * Create relations for publications
     *
     * @param $slimRequest Request Slim request object
     * @param $response Response object
     * @param array $args arguments
     *
     * @return Response
     */
    public function relatePublication($slimRequest, $response, $args)
    {
        $request = $this->getRequest();
        $submission = $this->getAuthorizedContextObject(ASSOC_TYPE_SUBMISSION);
        $publication = Repo::publication()->get((int) $args['publicationId']);

        if (!$publication) {
            return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
        }

        if ($submission->getId() !== $publication->getData('submissionId')) {
            return $response->withStatus(403)->withJsonError('api.publications.403.submissionsDidNotMatch');
        }

        // Only accept publication props for relations
        $params = array_intersect_key($slimRequest->getParsedBody(), array_flip(['relationStatus', 'vorDoi']));

        $params = $this->convertStringsToSchema(PKPSchemaService::SCHEMA_PUBLICATION, $params);

        // Required in this handler
        if (!isset($params['relationStatus'])) {
            return $response->withStatus(400)->withJson(['relationStatus' => [__('validator.filled')]]);
        }

        // Validate against the schema
        $submissionContext = $request->getContext();
        if (!$submissionContext || $submissionContext->getId() !== $submission->getData('contextId')) {
            $submissionContext = Services::get('context')->get($submission->getData('contextId'));
        }
        $primaryLocale = $publication->getData('locale');
        $allowedLocales = $submissionContext->getData('supportedSubmissionLocales');

        $errors = Repo::publication()->validate($publication, $params, $allowedLocales, $primaryLocale);

        if (!empty($errors)) {
            return $response->withStatus(400)->withJson($errors);
        }

        Repo::publication()->relate(
            $publication,
            $params['relationStatus'],
            $params['vorDoi'] ?? ''
        );

        $publication = Repo::publication()->get($publication->getId());

        $userGroupDao = DAORegistry::getDAO('UserGroupDAO'); /** @var UserGroupDAO $userGroupDao */
        $userGroups = $userGroupDao->getByContextId($submission->getData('contextId'))->toArray();

        return $response->withJson(
            Repo::publication()->getSchemaMap($submission, $userGroups)->map($publication),
            200
        );
    }
}
