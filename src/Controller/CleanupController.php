<?php

declare(strict_types=1);

namespace App\Controller;

use App\Middleware\DuplicateUserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CleanupController extends AbstractController
{
    #[Route('/cleanup', name: 'app_cleanup', methods: ['GET'])]
    public function index(DuplicateUserRepository $repo): Response
    {
        return $this->render('cleanup/index.html.twig', [
            'dbConfigured' => $repo->isConfigured(),
        ]);
    }

    #[Route('/cleanup/analyse', name: 'app_cleanup_analyse', methods: ['POST'])]
    public function analyse(DuplicateUserRepository $repo): JsonResponse
    {
        if (!$repo->isConfigured()) {
            return $this->json(
                ['ok' => false, 'error' => 'DB nicht konfiguriert.'],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        try {
            $duplicates  = $repo->findDuplicates();
            $totalExtra  = array_sum(array_column($duplicates, 'count')) - count($duplicates);
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json([
            'ok'          => true,
            'duplicates'  => $duplicates,
            'emailCount'  => count($duplicates),
            'totalExtra'  => $totalExtra,
        ]);
    }

    #[Route('/cleanup/execute', name: 'app_cleanup_execute', methods: ['POST'])]
    public function execute(DuplicateUserRepository $repo): JsonResponse
    {
        if (!$repo->isConfigured()) {
            return $this->json(
                ['ok' => false, 'error' => 'DB nicht konfiguriert.'],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        try {
            $deleted = $repo->deleteDuplicates();
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json(['ok' => true, 'deleted' => $deleted]);
    }
}
