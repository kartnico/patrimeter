<?php

declare(strict_types=1);

namespace Patrimeter\Controller;

use Patrimeter\Application\Calendar;
use Patrimeter\Application\Family\FamilyPortfolio;
use Patrimeter\Application\Family\Perimeter;
use Patrimeter\Application\NotFound;
use Patrimeter\Application\Transfer\DataTransfer;
use Patrimeter\Application\ValidationFailed;
use Patrimeter\Domain\Month;
use Patrimeter\Http\Sentences;
use Patrimeter\Security\CurrentUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapUploadedFile;
use Symfony\Component\Routing\Attribute\Route;

use function Symfony\Component\Translation\t;

/**
 * Members of a family account (FamilyPortfolio): each one exports their data from their own account, every month; the
 * family account imports this file for that member, the new import replacing the previous one. Two members at most.
 */
final class FamilyController extends AbstractController
{
    private const string ID = '\d{1,9}';

    public function __construct(
        private readonly FamilyPortfolio $family,
        private readonly CurrentUser $currentUser,
        private readonly Calendar $calendar,
    ) {}

    #[Route('/famille', name: 'app_family', methods: ['GET'])]
    public function index(): Response
    {
        $owner = $this->currentUser->id();
        $current = $this->calendar->currentMonth();
        $members = [];
        foreach ($this->family->members($owner) as $member) {
            $portfolio = $this->family->portfolio($owner, Perimeter::member($member->id));
            $snapshot = $portfolio->calculator()->snapshot($current);
            $exported = $member->exportedOn === null ? null : Month::fromDate($member->exportedOn);
            $members[] = [
                'member' => $member,
                'accounts' => count($portfolio->accounts),
                'net' => $snapshot->hasData() ? $snapshot->net() : null,
                'budget' => $member->budget !== null,
                // File of an earlier month: the current month's is expected.
                'stale' => $exported === null || $exported->compare($current) < 0,
            ];
        }

        return $this->render('family/index.html.twig', [
            'members' => $members,
            'can_add' => count($members) < FamilyPortfolio::MAX_MEMBERS,
            'current_month' => $current,
        ]);
    }

    #[Route('/famille/membres', name: 'app_family_member_add', methods: ['POST'])]
    public function add(Request $request, #[MapUploadedFile(name: 'fichier')] ?UploadedFile $file = null): Response
    {
        return $this->import(null, $request->request->getString('name'), $file);
    }

    #[Route('/famille/membres/{id}', name: 'app_family_member_update', requirements: ['id' => self::ID], methods: ['POST'])]
    public function update(int $id, #[MapUploadedFile(name: 'fichier')] ?UploadedFile $file = null): Response
    {
        return $this->import($id, null, $file);
    }

    #[Route('/famille/membres/{id}/supprimer', name: 'app_family_member_delete', requirements: ['id' => self::ID], methods: ['POST'])]
    public function delete(int $id): Response
    {
        try {
            $member = $this->family->remove($this->currentUser->id(), $id);
        } catch (NotFound) {
            throw $this->createNotFoundException();
        }
        $this->addFlash('success', t('Membre « %name% » retiré, avec ses données importées.', ['%name%' => $member->name]));

        return $this->redirectToRoute('app_family', status: Response::HTTP_SEE_OTHER);
    }

    private function import(?int $id, ?string $name, ?UploadedFile $file): Response
    {
        if ($id !== null && $this->family->find($this->currentUser->id(), $id) === null) {
            throw $this->createNotFoundException();
        }
        $json = $file !== null && $file->isValid() && $file->getSize() <= DataTransfer::MAX_BYTES
            ? file_get_contents($file->getPathname())
            : false;
        try {
            if ($json === false) {
                throw new ValidationFailed(['file' => 'Choisissez un fichier d\'export de Patrimeter (2 Mo au plus).']);
            }
            $member = $this->family->import($this->currentUser->id(), $id, $name, $json);
            $this->addFlash('success', t('Données de %name% importées : elles remplacent le précédent import.', ['%name%' => $member->name]));
        } catch (NotFound) {
            throw $this->createNotFoundException();
        } catch (ValidationFailed $failure) {
            $this->addFlash('error', Sentences::of(t('Rien n\'a été importé.'), ...array_values($failure->errors)));
        }

        return $this->redirectToRoute('app_family', status: Response::HTTP_SEE_OTHER);
    }
}
