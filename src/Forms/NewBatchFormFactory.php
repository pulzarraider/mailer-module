<?php
declare(strict_types=1);

namespace Remp\MailerModule\Forms;

use Nette\Application\UI\Form;
use Nette\Forms\Controls\SubmitButton;
use Nette\Security\User;
use Nette\SmartObject;
use Nette\Utils\ArrayHash;
use Remp\MailerModule\Models\Auth\PermissionManager;
use Remp\MailerModule\Models\Segment\Aggregator;
use Remp\MailerModule\Repositories\BatchesRepository;
use Remp\MailerModule\Repositories\BatchTemplatesRepository;
use Remp\MailerModule\Repositories\JobsRepository;
use Remp\MailerModule\Repositories\ListsRepository;
use Remp\MailerModule\Repositories\TemplatesRepository;
use Tracy\Debugger;

class NewBatchFormFactory
{
    use SmartObject;

    private const FORM_ACTION_SAVE_START = 'save_start';

    private $jobsRepository;

    private $batchesRepository;

    private $templatesRepository;

    private $batchTemplatesRepository;

    private $listsRepository;

    private $segmentAggregator;

    private $permissionManager;

    private $user;

    public $onSuccess;

    public function __construct(
        JobsRepository $jobsRepository,
        BatchesRepository $batchesRepository,
        TemplatesRepository $templatesRepository,
        BatchTemplatesRepository $batchTemplatesRepository,
        ListsRepository $listsRepository,
        Aggregator $segmentAggregator,
        PermissionManager $permissionManager,
        User $user
    ) {
        $this->jobsRepository = $jobsRepository;
        $this->batchesRepository = $batchesRepository;
        $this->templatesRepository = $templatesRepository;
        $this->batchTemplatesRepository = $batchTemplatesRepository;
        $this->listsRepository = $listsRepository;
        $this->segmentAggregator = $segmentAggregator;
        $this->permissionManager = $permissionManager;
        $this->user = $user;
    }

    public function create(?int $jobId)
    {
        $form = new Form;
        $form->addProtection();

        if ($jobId === null) {
            $segments = [];
            $segmentList = $this->segmentAggregator->list();
            array_walk($segmentList, function ($segment) use (&$segments) {
                $segments[$segment['provider']][$segment['provider'] . '::' . $segment['code']] = $segment['name'];
            });
            if ($this->segmentAggregator->hasErrors()) {
                $form->addError('Unable to fetch list of segments, please check the application configuration.');
                Debugger::log($this->segmentAggregator->getErrors()[0], Debugger::WARNING);
            }

            $form->addSelect('segment_code', 'Segment', $segments)
                ->setPrompt('Select segment')
                ->setRequired("Field 'Segment' is required.");
        }

        $methods = [
            'random' => 'Random',
            'sequential' => 'Sequential',
        ];
        $form->addSelect('method', 'Method', $methods);

        $listPairs = $this->listsRepository->all()->fetchPairs('id', 'title');

        $form->addSelect('mail_type_id', 'Newsletter list', $listPairs)
            ->setPrompt('Select newsletter list');

        if (isset($_POST['mail_type_id'])) {
            $templateList = $this->templatesRepository->pairs((int) $_POST['mail_type_id']);
        } else {
            $templateList = null;
        }
        $form->addSelect('template_id', 'Email A alternative', $templateList)
            ->setPrompt('Select email')
            ->setRequired('Email for A alternative is required');

        $form->addSelect('b_template_id', 'Email B alternative', $templateList)
            ->setPrompt('Select alternative email');

        $form->addText('email_count', 'Number of emails');

        $form->addText('start_at', 'Start date');

        $form->addHidden('job_id', $jobId);

        $form->addSubmit('save')
            ->getControlPrototype()
            ->setName('button')
            ->setHtml('<i class="zmdi zmdi-mail-send"></i> Save');

        if ($this->permissionManager->isAllowed($this->user, 'batch', 'start')) {
            $form->addSubmit(self::FORM_ACTION_SAVE_START)
                ->getControlPrototype()
                ->setName('button')
                ->setHtml('<i class="zmdi zmdi-mail-send"></i> Save and start');
        }

        $form->onSuccess[] = [$this, 'formSucceeded'];
        return $form;
    }

    public function formSucceeded(Form $form, ArrayHash $values): void
    {
        if (!$values['job_id']) {
            $segment = explode('::', $values['segment_code']);
            $values['job_id'] = $this->jobsRepository->add($segment[1], $segment[0])->id;
        } else {
            $values['job_id'] = (int)$values['job_id'];
        }

        $batch = $this->batchesRepository->add(
            (int) $values['job_id'],
            !empty($values['email_count']) ? (int)$values['email_count'] : null,
            $values['start_at'],
            $values['method']
        );

        $this->batchTemplatesRepository->add(
            (int) $values['job_id'],
            $batch->id,
            $values['template_id']
        );

        if ($values['b_template_id'] !== null) {
            $this->batchTemplatesRepository->add(
                (int) $values['job_id'],
                $batch->id,
                $values['b_template_id']
            );
        }

        if ($this->permissionManager->isAllowed($this->user, 'batch', 'start')) {
            /** @var SubmitButton $buttonSaveStart */
            $buttonSaveStart = $form[self::FORM_ACTION_SAVE_START];
            if ($buttonSaveStart->isSubmittedBy()) {
                $this->batchesRepository->updateStatus($batch, BatchesRepository::STATUS_READY_TO_PROCESS_AND_SEND);
            }
        }

        ($this->onSuccess)($batch->job);
    }
}
