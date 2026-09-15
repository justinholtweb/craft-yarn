<?php

namespace justinholtweb\yarn\controllers;

use justinholtweb\yarn\Plugin;
use justinholtweb\yarn\services\Export;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * The graph as a file.
 */
class ExportController extends BaseController
{
    public function actionIndex(): Response
    {
        $this->requirePermission(Plugin::PERMISSION_EXPORT);

        $format = (string)$this->request->getParam('format', Export::FORMAT_CSV);

        if (!in_array($format, Export::FORMATS, true)) {
            throw new BadRequestHttpException("Unsupported export format: $format");
        }

        $site = $this->site();
        $graph = $this->graph($site);
        $export = Plugin::getInstance()->export;

        $filename = sprintf('yarn-%s-%s.%s', $site->handle, date('Y-m-d'), $export->extension($format));

        return $this->response->sendContentAsFile(
            $export->render($graph, $format),
            $filename,
            ['mimeType' => $export->mimeType($format)],
        );
    }
}
