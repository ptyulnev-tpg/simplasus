<?php

declare(strict_types=1);

namespace App\Controller;

use App\Middleware\ApiUserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ApiUserController extends AbstractController
{
    #[Route('/api-user', name: 'app_api_user', methods: ['GET'])]
    public function index(ApiUserRepository $repo): Response
    {
        return $this->render('api_user/index.html.twig', [
            'dbConfigured' => $repo->isConfigured(),
        ]);
    }

    #[Route('/api-user/generate-password', name: 'app_api_user_generate_password', methods: ['POST'])]
    public function generatePassword(): JsonResponse
    {
        $chars    = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%&*';
        $length   = 16;
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        return $this->json(['ok' => true, 'plaintext' => $password, 'hash' => $hash]);
    }

    #[Route('/api-user/generate', name: 'app_api_user_generate', methods: ['POST'])]
    public function generate(Request $request, ApiUserRepository $repo): JsonResponse
    {
        if (!$repo->isConfigured()) {
            return $this->json(
                ['ok' => false, 'error' => 'DB nicht konfiguriert. Bitte PEGASUS_STAGING_DB_* in .env.local setzen.'],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        $data           = json_decode($request->getContent(), true) ?? [];
        $apiPrefix      = trim((string) ($data['apiPrefix'] ?? ''));
        $password       = trim((string) ($data['password'] ?? ''));
        $merchantFilter = trim((string) ($data['merchantFilter'] ?? ''));

        if ($apiPrefix === '') {
            return $this->json(['ok' => false, 'error' => 'API-Prefix ist erforderlich.'], Response::HTTP_BAD_REQUEST);
        }

        if ($password === '') {
            return $this->json(['ok' => false, 'error' => 'Passwort (Hash) ist erforderlich.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $inserts = $repo->generateInserts($apiPrefix, $password, $merchantFilter);
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json(['ok' => true, 'inserts' => $inserts, 'count' => count($inserts)]);
    }

    #[Route('/api-user/execute', name: 'app_api_user_execute', methods: ['POST'])]
    public function execute(Request $request, ApiUserRepository $repo): JsonResponse
    {
        if (!$repo->isConfigured()) {
            return $this->json(
                ['ok' => false, 'error' => 'DB nicht konfiguriert. Bitte PEGASUS_STAGING_DB_* in .env.local setzen.'],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        $data           = json_decode($request->getContent(), true) ?? [];
        $apiPrefix      = trim((string) ($data['apiPrefix'] ?? ''));
        $password       = trim((string) ($data['password'] ?? ''));
        $email          = trim((string) ($data['email'] ?? ''));
        $merchantFilter = trim((string) ($data['merchantFilter'] ?? ''));

        if ($apiPrefix === '' || $password === '') {
            return $this->json(['ok' => false, 'error' => 'apiPrefix und password sind erforderlich.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $results = $repo->executeInserts($apiPrefix, $password, $email, $merchantFilter);
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $okCount   = count(array_filter($results, fn(array $r): bool => $r['ok']));
        $failCount = count($results) - $okCount;

        return $this->json([
            'ok'        => true,
            'results'   => $results,
            'okCount'   => $okCount,
            'failCount' => $failCount,
        ]);
    }
}
