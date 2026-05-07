<?php

declare(strict_types=1);

namespace App\Controller;

use App\Middleware\ApiUserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ApiUserController extends AbstractController
{
    public function __construct(
        #[Autowire(service: 'app.repo.api_user.staging')]
        private readonly ApiUserRepository $stagingRepo,
        #[Autowire(service: 'app.repo.api_user.live')]
        private readonly ApiUserRepository $liveRepo,
    ) {
    }

    #[Route('/api-user', name: 'app_api_user', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('api_user/index.html.twig', [
            'stagingConfigured' => $this->stagingRepo->isConfigured(),
            'liveConfigured'    => $this->liveRepo->isConfigured(),
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

        return $this->json([
            'ok'        => true,
            'plaintext' => $password,
            'hash'      => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
        ]);
    }

    #[Route('/api-user/execute', name: 'app_api_user_execute', methods: ['POST'])]
    public function execute(Request $request): JsonResponse
    {
        $data           = json_decode($request->getContent(), true) ?? [];
        $context        = (string) ($data['context'] ?? 'staging');
        $apiPrefix      = trim((string) ($data['apiPrefix'] ?? ''));
        $password       = trim((string) ($data['password'] ?? ''));
        $email          = trim((string) ($data['email'] ?? ''));
        $merchantFilter = trim((string) ($data['merchantFilter'] ?? ''));

        $repo = $context === 'live' ? $this->liveRepo : $this->stagingRepo;

        if (!$repo->isConfigured()) {
            return $this->json(
                ['ok' => false, 'error' => 'DB nicht konfiguriert.'],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

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
