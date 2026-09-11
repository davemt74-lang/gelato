<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

$user = app_require_auth();
$pdo = app_pdo();
$organizationId = (int)$user['organization_id'];

function knowledge_uuid_v4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function knowledge_normalize_text(string $content): string
{
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
    if (!mb_check_encoding($content, 'UTF-8')) {
        $converted = @mb_convert_encoding($content, 'UTF-8', 'UTF-8, Windows-1252, ISO-8859-1');
        if (is_string($converted)) {
            $content = $converted;
        }
    }
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    $content = preg_replace('/[\t ]+\n/', "\n", $content) ?? $content;
    $content = preg_replace('/\n{4,}/', "\n\n\n", $content) ?? $content;
    return trim($content);
}

function knowledge_chunks(string $content, int $maximumCharacters = 1800, int $overlap = 180): array
{
    $length = mb_strlen($content, 'UTF-8');
    if ($length === 0) {
        return [];
    }
    $chunks = [];
    $start = 0;
    while ($start < $length) {
        $remaining = $length - $start;
        $take = min($maximumCharacters, $remaining);
        $piece = mb_substr($content, $start, $take, 'UTF-8');
        if ($remaining > $maximumCharacters) {
            $minimumBreak = (int)floor($maximumCharacters * 0.62);
            $newline = mb_strrpos($piece, "\n", 0, 'UTF-8');
            $space = mb_strrpos($piece, ' ', 0, 'UTF-8');
            $breakAt = max($newline === false ? 0 : $newline, $space === false ? 0 : $space);
            if ($breakAt >= $minimumBreak) {
                $piece = mb_substr($piece, 0, $breakAt, 'UTF-8');
                $take = $breakAt;
            }
        }
        $piece = trim($piece);
        if ($piece !== '') {
            $chunks[] = $piece;
        }
        if ($start + $take >= $length) {
            break;
        }
        $start += max(1, $take - $overlap);
    }
    return $chunks;
}

function knowledge_file_content(array $file): array
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE
            ? 'The uploaded text file is too large.'
            : 'The text file could not be uploaded.');
    }
    $size = (int)($file['size'] ?? 0);
    if ($size < 1 || $size > 1024 * 1024) {
        throw new RuntimeException('Text files must be between 1 byte and 1 MB.');
    }
    $name = basename((string)($file['name'] ?? 'knowledge.txt'));
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed = ['txt', 'md', 'markdown', 'csv', 'json', 'html', 'htm', 'xml'];
    if (!in_array($extension, $allowed, true)) {
        throw new RuntimeException('Supported files are TXT, Markdown, CSV, JSON, HTML, and XML.');
    }
    $temporaryPath = (string)($file['tmp_name'] ?? '');
    $raw = is_uploaded_file($temporaryPath) ? file_get_contents($temporaryPath) : false;
    if (!is_string($raw)) {
        throw new RuntimeException('The uploaded text file could not be read.');
    }
    $mimeType = 'text/plain';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($temporaryPath);
        if (is_string($detected) && $detected !== '') {
            $mimeType = mb_substr($detected, 0, 150);
        }
    }
    if ($extension === 'json') {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $raw = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
    } elseif (in_array($extension, ['html', 'htm'], true)) {
        $raw = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return [knowledge_normalize_text($raw), $name, $mimeType];
}

function knowledge_require(array $user, string $permission): void
{
    if (!app_has_permission($permission, $user)) {
        app_json_response(['ok' => false, 'message' => 'You do not have permission to complete this Knowledge Center action.'], 403);
    }
}

function knowledge_document_payload(array $row): array
{
    return [
        'id' => (string)$row['public_id'],
        'title' => (string)$row['title'],
        'sourceType' => (string)$row['source_type'],
        'originalFilename' => (string)($row['original_filename'] ?? ''),
        'mimeType' => (string)($row['mime_type'] ?? ''),
        'content' => (string)($row['content'] ?? ''),
        'contentHash' => (string)$row['content_sha256'],
        'status' => (string)$row['status'],
        'agentEnabled' => (bool)$row['agent_enabled'],
        'version' => (int)$row['version'],
        'chunkCount' => (int)($row['chunk_count'] ?? 0),
        'characterCount' => (int)($row['character_count'] ?? mb_strlen((string)($row['content'] ?? ''), 'UTF-8')),
        'createdAt' => (string)$row['created_at'],
        'updatedAt' => (string)$row['updated_at'],
    ];
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        knowledge_require($user, 'knowledge.view');
        $publicId = trim((string)($_GET['id'] ?? ''));
        if ($publicId !== '') {
            $statement = $pdo->prepare(
                "SELECT d.*, COUNT(c.id) AS chunk_count, CHAR_LENGTH(d.content) AS character_count
                 FROM knowledge_documents d
                 LEFT JOIN knowledge_document_chunks c ON c.document_id = d.id
                 WHERE d.organization_id = :organization_id AND d.public_id = :public_id AND d.archived_at IS NULL
                 GROUP BY d.id LIMIT 1"
            );
            $statement->execute(['organization_id' => $organizationId, 'public_id' => $publicId]);
            $row = $statement->fetch();
            if (!$row) {
                app_json_response(['ok' => false, 'message' => 'Knowledge document not found.'], 404);
            }
            app_json_response(['ok' => true, 'document' => knowledge_document_payload($row)]);
        }
        $statement = $pdo->prepare(
            "SELECT d.*, COUNT(c.id) AS chunk_count, CHAR_LENGTH(d.content) AS character_count
             FROM knowledge_documents d
             LEFT JOIN knowledge_document_chunks c ON c.document_id = d.id
             WHERE d.organization_id = :organization_id AND d.archived_at IS NULL
             GROUP BY d.id ORDER BY d.updated_at DESC, d.id DESC"
        );
        $statement->execute(['organization_id' => $organizationId]);
        $documents = array_map('knowledge_document_payload', $statement->fetchAll());
        app_json_response(['ok' => true, 'documents' => $documents, 'summary' => [
            'documents' => count($documents),
            'agentEnabled' => count(array_filter($documents, static fn(array $document): bool => $document['agentEnabled'])),
            'chunks' => array_sum(array_column($documents, 'chunkCount')),
        ]]);
    }

    if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'DELETE'], true)) {
        header('Allow: GET, POST, DELETE');
        app_json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
    }

    $input = str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') ? $_POST : app_json_input();
    app_verify_request_csrf(is_array($input) ? $input : []);

    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        knowledge_require($user, 'knowledge.delete');
        $publicId = trim((string)($input['id'] ?? ''));
        if ($publicId === '') {
            app_json_response(['ok' => false, 'message' => 'A document identifier is required.'], 422);
        }
        $lookup = $pdo->prepare('SELECT id, title FROM knowledge_documents WHERE organization_id = ? AND public_id = ? AND archived_at IS NULL LIMIT 1');
        $lookup->execute([$organizationId, $publicId]);
        $document = $lookup->fetch();
        if (!$document) {
            app_json_response(['ok' => false, 'message' => 'Knowledge document not found.'], 404);
        }
        $delete = $pdo->prepare('DELETE FROM knowledge_documents WHERE id = ? AND organization_id = ?');
        $delete->execute([(int)$document['id'], $organizationId]);
        app_audit($pdo, $organizationId, (int)$user['id'], 'knowledge.deleted', 'knowledge_document', $publicId, ['title' => $document['title']], null);
        app_json_response(['ok' => true, 'message' => 'Knowledge document deleted.']);
    }

    $publicId = trim((string)($input['id'] ?? ''));
    $isUpdate = $publicId !== '';
    knowledge_require($user, $isUpdate ? 'knowledge.edit' : 'knowledge.create');

    $sourceType = 'manual';
    $originalFilename = null;
    $mimeType = null;
    $content = (string)($input['content'] ?? '');
    if (isset($_FILES['file']) && is_array($_FILES['file']) && (int)($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        [$content, $originalFilename, $mimeType] = knowledge_file_content($_FILES['file']);
        $sourceType = 'upload';
    } else {
        $content = knowledge_normalize_text($content);
    }
    if ($content === '') {
        app_json_response(['ok' => false, 'message' => 'Enter document content or select a supported text file.'], 422);
    }
    if (mb_strlen($content, 'UTF-8') > 1000000) {
        app_json_response(['ok' => false, 'message' => 'Knowledge documents are limited to one million characters.'], 422);
    }
    $title = trim((string)($input['title'] ?? ''));
    if ($title === '' && $originalFilename) {
        $title = pathinfo($originalFilename, PATHINFO_FILENAME);
    }
    if ($title === '' || mb_strlen($title, 'UTF-8') > 240) {
        app_json_response(['ok' => false, 'message' => 'Enter a document title no longer than 240 characters.'], 422);
    }
    $agentEnabledValue = strtolower(trim((string)($input['agentEnabled'] ?? 'true')));
    $agentEnabled = in_array($agentEnabledValue, ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
    $chunks = knowledge_chunks($content);
    if (!$chunks) {
        app_json_response(['ok' => false, 'message' => 'The document does not contain indexable text.'], 422);
    }

    $pdo->beginTransaction();
    try {
        $previous = null;
        if ($isUpdate) {
            $lookup = $pdo->prepare('SELECT * FROM knowledge_documents WHERE organization_id = ? AND public_id = ? AND archived_at IS NULL LIMIT 1 FOR UPDATE');
            $lookup->execute([$organizationId, $publicId]);
            $previous = $lookup->fetch();
            if (!$previous) {
                throw new DomainException('Knowledge document not found.');
            }
            if ($sourceType === 'manual') {
                $sourceType = (string)$previous['source_type'];
                $originalFilename = $previous['original_filename'];
                $mimeType = $previous['mime_type'];
            }
            $statement = $pdo->prepare(
                "UPDATE knowledge_documents SET title = :title, source_type = :source_type, original_filename = :original_filename,
                   mime_type = :mime_type, content = :content, content_sha256 = :content_sha256, status = 'published',
                   agent_enabled = :agent_enabled, version = version + 1, updated_by = :updated_by
                 WHERE id = :id AND organization_id = :organization_id"
            );
            $statement->execute([
                'title' => $title, 'source_type' => $sourceType, 'original_filename' => $originalFilename,
                'mime_type' => $mimeType, 'content' => $content, 'content_sha256' => hash('sha256', $content),
                'agent_enabled' => $agentEnabled, 'updated_by' => (int)$user['id'],
                'id' => (int)$previous['id'], 'organization_id' => $organizationId,
            ]);
            $documentId = (int)$previous['id'];
            $pdo->prepare('DELETE FROM knowledge_document_chunks WHERE document_id = ? AND organization_id = ?')->execute([$documentId, $organizationId]);
        } else {
            $publicId = knowledge_uuid_v4();
            $statement = $pdo->prepare(
                "INSERT INTO knowledge_documents
                 (organization_id, public_id, title, source_type, original_filename, mime_type, content, content_sha256, status, agent_enabled, created_by, updated_by)
                 VALUES (:organization_id, :public_id, :title, :source_type, :original_filename, :mime_type, :content, :content_sha256, 'published', :agent_enabled, :user_id, :user_id)"
            );
            $statement->execute([
                'organization_id' => $organizationId, 'public_id' => $publicId, 'title' => $title,
                'source_type' => $sourceType, 'original_filename' => $originalFilename, 'mime_type' => $mimeType,
                'content' => $content, 'content_sha256' => hash('sha256', $content),
                'agent_enabled' => $agentEnabled, 'user_id' => (int)$user['id'],
            ]);
            $documentId = (int)$pdo->lastInsertId();
        }

        $insertChunk = $pdo->prepare(
            'INSERT INTO knowledge_document_chunks (organization_id, document_id, chunk_index, content, content_sha256, token_estimate) VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($chunks as $index => $chunk) {
            $insertChunk->execute([$organizationId, $documentId, $index, $chunk, hash('sha256', $chunk), (int)ceil(mb_strlen($chunk, 'UTF-8') / 4)]);
        }
        app_audit(
            $pdo,
            $organizationId,
            (int)$user['id'],
            $isUpdate ? 'knowledge.updated' : 'knowledge.created',
            'knowledge_document',
            $publicId,
            $previous ? ['title' => $previous['title'], 'version' => $previous['version'], 'agent_enabled' => (bool)$previous['agent_enabled']] : null,
            ['title' => $title, 'chunks' => count($chunks), 'agent_enabled' => (bool)$agentEnabled, 'source_type' => $sourceType]
        );
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($error instanceof DomainException) {
            app_json_response(['ok' => false, 'message' => $error->getMessage()], 404);
        }
        throw $error;
    }

    app_json_response(['ok' => true, 'message' => $isUpdate ? 'Knowledge document updated and re-indexed.' : 'Knowledge document saved and indexed.', 'document' => [
        'id' => $publicId,
        'title' => $title,
        'sourceType' => $sourceType,
        'agentEnabled' => (bool)$agentEnabled,
        'chunkCount' => count($chunks),
        'contentHash' => hash('sha256', $content),
    ]]);
} catch (PDOException $error) {
    app_json_response(['ok' => false, 'message' => 'The public-agent migration has not been imported. Import database/20260804_public_agent_knowledge_center.sql once.'], 409);
} catch (RuntimeException $error) {
    app_json_response(['ok' => false, 'message' => $error->getMessage()], 422);
} catch (Throwable $error) {
    app_json_response(['ok' => false, 'message' => 'The Knowledge Center request could not be completed.'], 500);
}
