<?php

declare(strict_types=1);

namespace Patrimeter\Controller;

use Patrimeter\Application\Transfer\CsvImport;
use Patrimeter\Application\Transfer\CsvImportFailed;
use Patrimeter\Application\Transfer\DataTransfer;
use Patrimeter\Application\ValidationFailed;
use Patrimeter\Http\Sentences;
use Patrimeter\Security\CurrentUser;
use Patrimeter\Security\Reauthentication;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Attribute\MapUploadedFile;
use Symfony\Component\Routing\Attribute\Route;

use function Symfony\Component\Translation\t;

/**
 * Your data: export as a JSON file (in clear, after identity confirmation) and import of this file into an empty
 * account, on this instance or another (docs/export.md); bulk creation of accounts and properties from a CSV file
 * (CsvImport, documentation "Import CSV"), with a model file to fill in.
 */
final class DataTransferController extends AbstractController
{
    public function __construct(
        private readonly DataTransfer $transfer,
        private readonly CsvImport $csv,
        private readonly CurrentUser $currentUser,
        private readonly Reauthentication $reauthentication,
    ) {}

    #[Route('/donnees', name: 'app_data', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->currentUser->user();

        return $this->render('data/index.html.twig', [
            'user' => $user,
            'reauthenticated' => $this->reauthentication->isFresh($request),
        ]);
    }

    #[Route('/donnees/export', name: 'app_data_export', methods: ['POST'])]
    public function export(Request $request): Response
    {
        if (!$this->reauthentication->isFresh($request)) {
            $this->addFlash('error', t('Pour exporter vos données, confirmez d\'abord votre identité.'));

            return $this->redirectToRoute('app_data', status: Response::HTTP_SEE_OTHER);
        }
        $export = $this->transfer->export($this->currentUser->id());
        // Readable JSON, accents and slashes as they are; < > & ' " escaped: the text entered by the user (names,
        // first names) can never be taken for HTML.
        $response = new JsonResponse($export);
        $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            'patrimeter-export-' . (is_string($export['exported_on']) ? $export['exported_on'] : 'donnees') . '.json',
        ));

        return $response;
    }

    #[Route('/donnees/import', name: 'app_data_import', methods: ['POST'])]
    public function import(#[MapUploadedFile(name: 'fichier')] ?UploadedFile $file = null): Response
    {
        $json = $file !== null && $file->isValid() && $file->getSize() <= DataTransfer::MAX_BYTES
            ? file_get_contents($file->getPathname())
            : false;
        try {
            if ($json === false) {
                throw new ValidationFailed(['file' => 'Choisissez un fichier d\'export de Patrimeter (2 Mo au plus).']);
            }
            $count = $this->transfer->import($this->currentUser->id(), $json);
            $this->addFlash('success', t('%count% compte importé, avec ses relevés.|%count% comptes importés, avec leurs relevés.', ['%count%' => $count]));

            return $this->redirectToRoute('app_accounts', status: Response::HTTP_SEE_OTHER);
        } catch (ValidationFailed $failure) {
            $this->addFlash('error', Sentences::of(t('Rien n\'a été importé.'), ...$failure->errors));

            return $this->redirectToRoute('app_data', status: Response::HTTP_SEE_OTHER);
        }
    }

    #[Route('/donnees/import-csv', name: 'app_data_import_csv', methods: ['POST'])]
    public function importCsv(Request $request, #[MapUploadedFile(name: 'fichier_csv')] ?UploadedFile $file = null): Response
    {
        $csv = $file !== null && $file->isValid() && $file->getSize() <= CsvImport::MAX_BYTES
            ? file_get_contents($file->getPathname())
            : false;
        try {
            if ($csv === false) {
                throw new CsvImportFailed(0, [t('Choisissez un fichier CSV (1 Mo au plus).')]);
            }
            $count = $this->csv->import($this->currentUser->id(), $csv, $request->getLocale());
            $this->addFlash('success', t('%count% compte créé depuis le fichier CSV.|%count% comptes créés depuis le fichier CSV.', ['%count%' => $count]));

            return $this->redirectToRoute('app_accounts', status: Response::HTTP_SEE_OTHER);
        } catch (CsvImportFailed $failure) {
            $this->addFlash('error', Sentences::of(
                t('Rien n\'a été importé.'),
                $failure->fileLine > 0 ? t('Ligne %line% :', ['%line%' => $failure->fileLine]) : null,
                ...$failure->reasons,
            ));

            return $this->redirectToRoute('app_data', ['_fragment' => 'csv'], Response::HTTP_SEE_OTHER);
        }
    }

    /** Model of the CSV file, in the language of the page: the columns and three example lines. */
    #[Route('/donnees/modele.csv', name: 'app_data_csv_model', methods: ['GET'])]
    public function csvModel(Request $request): Response
    {
        $english = $request->getLocale() === 'en';
        $response = new Response(CsvImport::model($request->getLocale()));
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $english ? 'patrimeter-accounts.csv' : 'patrimeter-comptes.csv',
        ));

        return $response;
    }
}
