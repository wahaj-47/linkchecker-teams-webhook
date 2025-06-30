<?php

namespace Drupal\linkchecker_teams_webhook\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\linkchecker_teams_webhook\LinkcheckerTeamsWebhookInterval;
use Drupal\node\Entity\NodeType;

/**
 * The linkchecker summary mail configuration form.
 */
class LinkcheckerTeamsWebhookConfigForm extends ConfigFormBase
{

    /**
     * {@inheritdoc}
     */
    public function getFormId()
    {
        return 'linkchecker_teams_webhook_config_form';
    }

    /**
     * {@inheritdoc}
     */
    protected function getEditableConfigNames()
    {
        return ['linkchecker_teams_webhook.settings'];
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state)
    {
        $config = $this->config('linkchecker_teams_webhook.settings');

        $form['interval'] = [
            '#title' => $this->t('Interval'),
            '#description' => $this->t('Adjust the interval for the mail'),
            '#type' => 'select',
            '#options' => [
                LinkcheckerTeamsWebhookInterval::DAILY => $this->t('Daily'),
                LinkcheckerTeamsWebhookInterval::WEEKLY => $this->t('Weekly'),
            ],
            '#default_value' => $config->get('interval'),
        ];
        $form['url'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Incoming webhook URL'),
            '#default_value' => $config->get('url'),
            '#maxlength' => 255,
        ];
        $form['run_cron'] = [
            '#type' => 'submit',
            '#value' => $this->t('Test Cron Job'),
            '#submit' => ['::runCronJob'],
        ];

        return parent::buildForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        $this->config('linkchecker_teams_webhook.settings')
            ->set('interval', $form_state->getValue('interval'))
            ->set('url', $form_state->getValue('url'))
            ->save();

        parent::submitForm($form, $form_state);
    }

    /**
     * Custom submission handler to run the cron job.
     */
    public function runCronJob(array &$form, FormStateInterface $form_state)
    {
        \Drupal::messenger()->addMessage('Report sent');
        $builder = \Drupal::service('linkchecker_teams_webhook.report_builder');
        $builder->runCronCheck(TRUE);
    }
}
