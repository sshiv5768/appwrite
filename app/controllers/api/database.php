<?php

use Utopia\App;
use Utopia\Exception;
use Utopia\Validator\Range;
use Utopia\Validator\WhiteList;
use Utopia\Validator\Text;
use Utopia\Validator\ArrayList;
use Utopia\Validator\JSON;
use Appwrite\Database\Database;
use Appwrite\Database\Document;
use Appwrite\Database\Validator\UID;
use Appwrite\Database\Validator\Key;
use Appwrite\Database\Validator\Structure;
use Appwrite\Database\Validator\Collection;
use Appwrite\Database\Validator\Authorization;
use Appwrite\Database\Exception\Authorization as AuthorizationException;
use Appwrite\Database\Exception\Structure as StructureException;
use Appwrite\Utopia\Response;

App::post('/v1/database/collections')
    ->desc('Create Collection')
    ->groups(['api', 'database'])
    ->label('event', 'database.collections.create')
    ->label('scope', 'collections.write')
    ->label('sdk.namespace', 'database')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.method', 'createCollection')
    ->label('sdk.description', '/docs/references/database/create-collection.md')
    ->param('name', '', new Text(128), 'Collection name. Max length: 128 chars.')
    ->param('read', [], new ArrayList(new Text(64)), 'An array of strings with read permissions. By default no user is granted with any read permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.')
    ->param('write', [], new ArrayList(new Text(64)), 'An array of strings with write permissions. By default no user is granted with any write permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.')
    ->param('rules', [], function ($projectDB) { return new ArrayList(new Collection($projectDB, [Database::COLLECTION_RULES], ['$collection' => Database::COLLECTION_RULES, '$permissions' => ['read' => [], 'write' => []]])); }, 'Array of [rule objects](/docs/rules). Each rule define a collection field name, data type and validation.', false, ['projectDB'])
    ->action(function ($name, $read, $write, $rules, $response, $projectDB, $audits) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Event\Event $audits */

        $parsedRules = [];

        foreach ($rules as &$rule) {
            $parsedRules[] = \array_merge([
                '$collection' => Database::COLLECTION_RULES,
                '$permissions' => [
                    'read' => $read,
                    'write' => $write,
                ],
            ], $rule);
        }

        try {
            $data = $projectDB->createDocument(Database::COLLECTION_COLLECTIONS, [
                '$collection' => Database::COLLECTION_COLLECTIONS,
                'name' => $name,
                'dateCreated' => \time(),
                'dateUpdated' => \time(),
                '$permissions' => [
                    'read' => $read,
                    'write' => $write,
                ],
                'rules' => $parsedRules,
            ]);
        } catch (AuthorizationException $exception) {
            throw new Exception('Unauthorized permissions', 401);
        } catch (StructureException $exception) {
            throw new Exception('Bad structure. '.$exception->getMessage(), 400);
        } catch (\Exception $exception) {
            throw new Exception('Failed saving document to DB', 500);
        }

        if (false === $data) {
            throw new Exception('Failed saving collection to DB', 500);
        }

        $audits
            ->setParam('event', 'database.collections.create')
            ->setParam('resource', 'database/collection/'.$data->getId())
            ->setParam('data', $data->getArrayCopy())
        ;

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic($data, Response::MODEL_COLLECTION);
    }, ['response', 'projectDB', 'audits']);

App::get('/v1/database/collections')
    ->desc('List Collections')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('sdk.namespace', 'database')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.method', 'listCollections')
    ->label('sdk.description', '/docs/references/database/list-collections.md')
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->param('limit', 25, new Range(0, 100), 'Results limit value. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, 40000), 'Results offset. The default value is 0. Use this param to manage pagination.', true)
    ->param('orderType', 'ASC', new WhiteList(['ASC', 'DESC'], true), 'Order result by ASC or DESC order.', true)
    ->action(function ($search, $limit, $offset, $orderType, $response, $projectDB) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */

        $results = $projectDB->find(Database::COLLECTION_COLLECTIONS, [
            'limit' => $limit,
            'offset' => $offset,
            'orderField' => 'name',
            'orderType' => $orderType,
            'orderCast' => 'string',
            'search' => $search,
        ]);

        $response->dynamic(new Document([
            'sum' => $projectDB->getSum(),
            'collections' => $results
        ]), Response::MODEL_COLLECTION_LIST);
    }, ['response', 'projectDB']);

App::get('/v1/database/collections/:collectionId')
    ->desc('Get Collection')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('sdk.namespace', 'database')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.method', 'getCollection')
    ->label('sdk.description', '/docs/references/database/get-collection.md')
    ->param('collectionId', '', new UID(), 'Collection unique ID.')
    ->action(function ($collectionId, $response, $projectDB) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */
        
        $collection = $projectDB->getDocument(Database::COLLECTION_COLLECTIONS, $collectionId, false);

        if (empty($collection->getId()) || Database::COLLECTION_COLLECTIONS != $collection->getCollection()) {
            throw new Exception('Collection not found', 404);
        }

        $response->dynamic($collection, Response::MODEL_COLLECTION);
    }, ['response', 'projectDB']);

App::put('/v1/database/collections/:collectionId')
    ->desc('Update Collection')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.write')
    ->label('event', 'database.collections.update')
    ->label('sdk.namespace', 'database')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.method', 'updateCollection')
    ->label('sdk.description', '/docs/references/database/update-collection.md')
    ->param('collectionId', '', new UID(), 'Collection unique ID.')
    ->param('name', null, new Text(128), 'Collection name. Max length: 128 chars.')
    ->param('read', [], new ArrayList(new Text(64)), 'An array of strings with read permissions. By default no user is granted with any read permissions. [learn more about permissions(/docs/permissions) and get a full list of available permissions.')
    ->param('write', [], new ArrayList(new Text(64)), 'An array of strings with write permissions. By default no user is granted with any write permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.')
    ->param('rules', [], function ($projectDB) { return new ArrayList(new Collection($projectDB, [Database::COLLECTION_RULES], ['$collection' => Database::COLLECTION_RULES, '$permissions' => ['read' => [], 'write' => []]])); }, 'Array of [rule objects](/docs/rules). Each rule define a collection field name, data type and validation.', true, ['projectDB'])
    ->action(function ($collectionId, $name, $read, $write, $rules, $response, $projectDB, $audits) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Event\Event $audits */

        $collection = $projectDB->getDocument(Database::COLLECTION_COLLECTIONS, $collectionId, false);

        if (empty($collection->getId()) || Database::COLLECTION_COLLECTIONS != $collection->getCollection()) {
            throw new Exception('Collection not found', 404);
        }

        $parsedRules = [];

        foreach ($rules as &$rule) {
            $parsedRules[] = \array_merge([
                '$collection' => Database::COLLECTION_RULES,
                '$permissions' => [
                    'read' => $read,
                    'write' => $write,
                ],
            ], $rule);
        }

        try {
            $collection = $projectDB->updateDocument(Database::COLLECTION_COLLECTIONS, $collection->getId(), \array_merge($collection->getArrayCopy(), [
                'name' => $name,
                'dateUpdated' => \time(),
                '$permissions' => [
                    'read' => $read,
                    'write' => $write,
                ],
                'rules' => $parsedRules,
            ]));
        } catch (AuthorizationException $exception) {
            throw new Exception('Unauthorized permissions', 401);
        } catch (StructureException $exception) {
            throw new Exception('Bad structure. '.$exception->getMessage(), 400);
        } catch (\Exception $exception) {
            throw new Exception('Failed saving document to DB', 500);
        }

        if (false === $collection) {
            throw new Exception('Failed saving collection to DB', 500);
        }

        $audits
            ->setParam('event', 'database.collections.update')
            ->setParam('resource', 'database/collections/'.$collection->getId())
            ->setParam('data', $collection->getArrayCopy())
        ;

        $response->dynamic($collection, Response::MODEL_COLLECTION);
    }, ['response', 'projectDB', 'audits']);

App::delete('/v1/database/collections/:collectionId')
    ->desc('Delete Collection')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.write')
    ->label('event', 'database.collections.delete')
    ->label('sdk.namespace', 'database')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.method', 'deleteCollection')
    ->label('sdk.description', '/docs/references/database/delete-collection.md')
    ->param('collectionId', '', new UID(), 'Collection unique ID.')
    ->action(function ($collectionId, $response, $projectDB, $webhooks, $audits) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Event\Event $webhooks */
        /** @var Appwrite\Event\Event $audits */

        $collection = $projectDB->getDocument(Database::COLLECTION_COLLECTIONS, $collectionId, false);

        if (empty($collection->getId()) || Database::COLLECTION_COLLECTIONS != $collection->getCollection()) {
            throw new Exception('Collection not found', 404);
        }

        if (!$projectDB->deleteDocument(Database::COLLECTION_COLLECTIONS, $collectionId)) {
            throw new Exception('Failed to remove collection from DB', 500);
        }
        
        $webhooks
            ->setParam('payload', $response->output($collection, Response::MODEL_COLLECTION))
        ;

        $audits
            ->setParam('event', 'database.collections.delete')
            ->setParam('resource', 'database/collections/'.$collection->getId())
            ->setParam('data', $collection->getArrayCopy())
        ;

        $response->noContent();
    }, ['response', 'projectDB', 'webhooks', 'audits']);

App::post('/v1/database/collections/:collectionId/documents')
    ->desc('Create Document')
    ->groups(['api', 'database'])
    ->label('event', 'database.documents.create')
    ->label('scope', 'documents.write')
    ->label('sdk.namespace', 'database')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.method', 'createDocument')
    ->label('sdk.description', '/docs/references/database/create-document.md')
    ->param('collectionId', null, new UID(), 'Collection unique ID. You can create a new collection with validation rules using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('data', [], new JSON(), 'Document data as JSON object.')
    ->param('read', [], new ArrayList(new Text(64)), 'An array of strings with read permissions. By default no user is granted with any read permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.')
    ->param('write', [], new ArrayList(new Text(64)), 'An array of strings with write permissions. By default no user is granted with any write permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.')
    ->action(function ($collectionId, $data, $read, $write, $response, $projectDB, $audits) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Event\Event $audits */
    
        $data = (\is_string($data)) ? \json_decode($data, true) : $data; // Cast to JSON array

        if (empty($data)) {
            throw new Exception('Missing payload', 400);
        }

        if (isset($data['$id'])) {
            throw new Exception('$id is not allowed for creating new documents, try update instead', 400);
        }
        
        $collection = $projectDB->getDocument(Database::COLLECTION_COLLECTIONS, $collectionId, false);

        if (\is_null($collection->getId()) || Database::COLLECTION_COLLECTIONS != $collection->getCollection()) {
            throw new Exception('Collection not found', 404);
        }

        $data['$collection'] = $collectionId; // Adding this param to make API easier for developers
        $data['$permissions'] = [
            'read' => $read,
            'write' => $write,
        ];

        /**
         * Set default collection values
         */
        foreach ($collection->getAttribute('rules') as $key => $rule) {
            $key = (isset($rule['key'])) ? $rule['key'] : '';
            $default = (isset($rule['default'])) ? $rule['default'] : null;

            if (!isset($data[$key])) {
                $data[$key] = $default;
            }
        }

        try {
            $data = $projectDB->createDocument($collectionId, $data);
        } catch (AuthorizationException $exception) {
            throw new Exception('Unauthorized permissions', 401);
        } catch (StructureException $exception) {
            throw new Exception('Bad structure. '.$exception->getMessage(), 400);
        } catch (\Exception $exception) {
            throw new Exception('Failed saving document to DB'.$exception->getMessage(), 500);
        }

        $audits
            ->setParam('event', 'database.documents.create')
            ->setParam('resource', 'database/document/'.$data['$id'])
            ->setParam('data', $data->getArrayCopy())
        ;

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
        ;

        $response->dynamic($data, Response::MODEL_ANY);
    }, ['response', 'projectDB', 'audits']);

App::get('/v1/database/collections/:collectionId/documents')
    ->desc('List Documents')
    ->groups(['api', 'database'])
    ->label('scope', 'documents.read')
    ->label('sdk.namespace', 'database')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.method', 'listDocuments')
    ->label('sdk.description', '/docs/references/database/list-documents.md')
    ->param('collectionId', null, new UID(), 'Collection unique ID. You can create a new collection with validation rules using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('filters', [], new ArrayList(new Text(128)), 'Array of filter strings. Each filter is constructed from a key name, comparison operator (=, !=, >, <, <=, >=) and a value. You can also use a dot (.) separator in attribute names to filter by child document attributes. Examples: \'name=John Doe\' or \'category.$id>=5bed2d152c362\'.', true)
    ->param('limit', 25, new Range(0, 1000), 'Maximum number of documents to return in response.  Use this value to manage pagination.', true)
    ->param('offset', 0, new Range(0, 900000000), 'Offset value. Use this value to manage pagination.', true)
    ->param('orderField', '$id', new Text(128), 'Document field that results will be sorted by.', true)
    ->param('orderType', 'ASC', new WhiteList(['DESC', 'ASC'], true), 'Order direction. Possible values are DESC for descending order, or ASC for ascending order.', true)
    ->param('orderCast', 'string', new WhiteList(['int', 'string', 'date', 'time', 'datetime'], true), 'Order field type casting. Possible values are int, string, date, time or datetime. The database will attempt to cast the order field to the value you pass here. The default value is a string.', true)
    ->param('search', '', new Text(256), 'Search query. Enter any free text search. The database will try to find a match against all document attributes and children. Max length: 256 chars.', true)
    ->action(function ($collectionId, $filters, $limit, $offset, $orderField, $orderType, $orderCast, $search, $response, $projectDB) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */

        $collection = $projectDB->getDocument(Database::COLLECTION_COLLECTIONS, $collectionId, false);

        if (\is_null($collection->getId()) || Database::COLLECTION_COLLECTIONS != $collection->getCollection()) {
            throw new Exception('Collection not found', 404);
        }

        $list = $projectDB->find($collection->getId(), [
            'limit' => $limit,
            'offset' => $offset,
            'orderField' => $orderField,
            'orderType' => $orderType,
            'orderCast' => $orderCast,
            'search' => $search,
            'filters' => $filters,
        ]);

        // if (App::isDevelopment()) {
        //     $collection
        //         ->setAttribute('debug', $projectDB->getDebug())
        //         ->setAttribute('limit', $limit)
        //         ->setAttribute('offset', $offset)
        //         ->setAttribute('orderField', $orderField)
        //         ->setAttribute('orderType', $orderType)
        //         ->setAttribute('orderCast', $orderCast)
        //         ->setAttribute('filters', $filters)
        //     ;
        // }

        $collection
            ->setAttribute('sum', $projectDB->getSum())
            ->setAttribute('documents', $list)
        ;

        $response->dynamic($collection, Response::MODEL_DOCUMENT_LIST);
    }, ['response', 'projectDB']);

App::get('/v1/database/collections/:collectionId/documents/:documentId')
    ->desc('Get Document')
    ->groups(['api', 'database'])
    ->label('scope', 'documents.read')
    ->label('sdk.namespace', 'database')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.method', 'getDocument')
    ->label('sdk.description', '/docs/references/database/get-document.md')
    ->param('collectionId', null, new UID(), 'Collection unique ID. You can create a new collection with validation rules using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('documentId', null, new UID(), 'Document unique ID.')
    ->action(function ($collectionId, $documentId, $request, $response, $projectDB) {
        /** @var Utopia\Swoole\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */

        $document = $projectDB->getDocument($collectionId, $documentId, false);
        $collection = $projectDB->getDocument(Database::COLLECTION_COLLECTIONS, $collectionId, false);

        if (empty($document->getArrayCopy()) || $document->getCollection() != $collection->getId()) { // Check empty
            throw new Exception('No document found', 404);
        }

        $response->dynamic($document, Response::MODEL_ANY);
    }, ['request', 'response', 'projectDB']);

App::patch('/v1/database/collections/:collectionId/documents/:documentId')
    ->desc('Update Document')
    ->groups(['api', 'database'])
    ->label('event', 'database.documents.update')
    ->label('scope', 'documents.write')
    ->label('sdk.namespace', 'database')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.method', 'updateDocument')
    ->label('sdk.description', '/docs/references/database/update-document.md')
    ->param('collectionId', null, new UID(), 'Collection unique ID. You can create a new collection with validation rules using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('documentId', null, new UID(), 'Document unique ID.')
    ->param('data', [], new JSON(), 'Document data as JSON object.')
    ->param('read', [], new ArrayList(new Text(64)), 'An array of strings with read permissions. By default no user is granted with any read permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.')
    ->param('write', [], new ArrayList(new Text(64)), 'An array of strings with write permissions. By default no user is granted with any write permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.')
    ->action(function ($collectionId, $documentId, $data, $read, $write, $response, $projectDB, $audits) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Event\Event $audits */

        $document = $projectDB->getDocument($collectionId, $documentId, false);
        $collection = $projectDB->getDocument(Database::COLLECTION_COLLECTIONS, $collectionId, false);

        $data = (\is_string($data)) ? \json_decode($data, true) : $data; // Cast to JSON array

        if (!\is_array($data)) {
            throw new Exception('Data param should be a valid JSON object', 400);
        }

        if (\is_null($collection->getId()) || Database::COLLECTION_COLLECTIONS != $collection->getCollection()) {
            throw new Exception('Collection not found', 404);
        }

        if (empty($document->getArrayCopy()) || $document->getCollection() != $collectionId) { // Check empty
            throw new Exception('No document found', 404);
        }

        //TODO check merge read write permissions

        if (!empty($read)) { // Overwrite permissions only when passed
            $data['$permissions']['read'] = $read;
        }

        if (!empty($write)) { // Overwrite permissions only when passed
            $data['$permissions']['write'] = $write;
        }

        $data = \array_merge($document->getArrayCopy(), $data);

        $data['$collection'] = $collection->getId(); // Make sure user don't switch collectionID
        $data['$id'] = $document->getId(); // Make sure user don't switch document unique ID

        if (empty($data)) {
            throw new Exception('Missing payload', 400);
        }

        try {
            $data = $projectDB->updateDocument($collection->getId(), $document->getId(), $data);
        } catch (AuthorizationException $exception) {
            throw new Exception('Unauthorized permissions', 401);
        } catch (StructureException $exception) {
            throw new Exception('Bad structure. '.$exception->getMessage(), 400);
        } catch (\Exception $exception) {
            throw new Exception('Failed saving document to DB', 500);
        }

        $audits
            ->setParam('event', 'database.documents.update')
            ->setParam('resource', 'database/document/'.$data->getId())
            ->setParam('data', $data->getArrayCopy())
        ;

        $response->dynamic($data, Response::MODEL_ANY);
    }, ['response', 'projectDB', 'audits']);

App::delete('/v1/database/collections/:collectionId/documents/:documentId')
    ->desc('Delete Document')
    ->groups(['api', 'database'])
    ->label('scope', 'documents.write')
    ->label('event', 'database.documents.delete')
    ->label('sdk.namespace', 'database')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.method', 'deleteDocument')
    ->label('sdk.description', '/docs/references/database/delete-document.md')
    ->param('collectionId', null, new UID(), 'Collection unique ID. You can create a new collection with validation rules using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('documentId', null, new UID(), 'Document unique ID.')
    ->action(function ($collectionId, $documentId, $response, $projectDB, $webhooks, $audits) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Event\Event $webhooks */
        /** @var Appwrite\Event\Event $audits */

        $document = $projectDB->getDocument($collectionId, $documentId, false);
        $collection = $projectDB->getDocument(Database::COLLECTION_COLLECTIONS, $collectionId, false);

        if (empty($document->getArrayCopy()) || $document->getCollection() != $collectionId) { // Check empty
            throw new Exception('No document found', 404);
        }

        if (\is_null($collection->getId()) || Database::COLLECTION_COLLECTIONS != $collection->getCollection()) {
            throw new Exception('Collection not found', 404);
        }

        try {
            $projectDB->deleteDocument($collectionId, $documentId);
        } catch (AuthorizationException $exception) {
            throw new Exception('Unauthorized permissions', 401);
        } catch (StructureException $exception) {
            throw new Exception('Bad structure. '.$exception->getMessage(), 400);
        } catch (\Exception $exception) {
            throw new Exception('Failed to remove document from DB', 500);
        }

        $webhooks
            ->setParam('payload', $response->output($document, Response::MODEL_ANY))
        ;
        
        $audits
            ->setParam('event', 'database.documents.delete')
            ->setParam('resource', 'database/document/'.$document->getId())
            ->setParam('data', $document->getArrayCopy()) // Audit document in case of malicious or disastrous action
        ;

        $response->noContent();
    }, ['response', 'projectDB', 'webhooks', 'audits']);