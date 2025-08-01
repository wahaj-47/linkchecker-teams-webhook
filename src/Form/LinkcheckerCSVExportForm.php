<?php

namespace Drupal\linkchecker_teams_webhook\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\NodeType;

class LinkcheckerCSVExportForm extends ConfigFormBase
{

    /**
     * {@inheritdoc}
     */
    public function getFormId()
    {
        return 'linkchecker_csv_export_form';
    }

    /**
     * {@inheritdoc}
     */
    protected function getEditableConfigNames()
    {
        return ['linkchecker_csv_export.settings'];
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state)
    {
        $config = $this->config('linkchecker_csv_export.settings');

        $form = parent::buildForm($form, $form_state);

        $form['content_types'] = [
            '#type' => 'select',
            '#title' => $this->t('Select Content Types'),
            '#options' => $this->getContentTypeOptions(),
            '#multiple' => TRUE,
            '#size' => 10,
            '#default_value' => $config->get("content_types") ?? [],
            '#attributes' => [
                'style' => 'width: 400px;',
            ],
        ];

        $form['actions']['submit']['#value'] = $this->t('Export');

        return $form;
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        $this->config('linkchecker_csv_export.settings')
            ->set('content_types', $form_state->getValue('content_types'))
            ->save();

        parent::submitForm($form, $form_state);

        $form_state->setRedirect('linkchecker_teams_webhook.export_csv');
    }

    protected function getContentTypeOptions()
    {
        $types = NodeType::loadMultiple();
        $options = [];
        foreach ($types as $type) {
            $options[$type->id()] = $type->label();
        }
        return $options;
    }
}
