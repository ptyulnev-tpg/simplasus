<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class GuidelinesController extends AbstractController
{
    #[Route('/guidelines', name: 'app_guidelines', methods: ['GET'])]
    public function index(): Response
    {
        $docsDir = $this->getParameter('kernel.project_dir') . '/docs';
        $files   = glob($docsDir . '/*.md') ?: [];

        $docs = array_map(
            static fn(string $path): array => [
                'name'     => pathinfo($path, PATHINFO_FILENAME),
                'filename' => basename($path),
            ],
            $files,
        );

        usort($docs, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $this->render('guidelines/index.html.twig', ['docs' => $docs]);
    }

    #[Route('/guidelines/{name}', name: 'app_guidelines_content', methods: ['GET'])]
    public function content(string $name): JsonResponse
    {
        $safe = basename($name);
        $path = $this->getParameter('kernel.project_dir') . '/docs/' . $safe . '.md';

        if (!is_file($path)) {
            throw new NotFoundHttpException();
        }

        return $this->json(['ok' => true, 'content' => file_get_contents($path)]);
    }
}
