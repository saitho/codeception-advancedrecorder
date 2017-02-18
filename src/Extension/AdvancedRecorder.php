<?php
namespace Codeception\Extension;

use Codeception\Event\StepEvent;
use Codeception\Event\TestEvent;
use Codeception\Events;
use Codeception\Exception\ExtensionException;
use Codeception\Lib\Interfaces\ScreenshotSaver;
use Codeception\Module\WebDriver;
use Codeception\Step;
use Codeception\Step\Comment as CommentStep;
use Codeception\Test\Descriptor;
use Codeception\TestInterface;
use Codeception\Util\FileSystem;
use Codeception\Util\Template;
use Symfony\Component\EventDispatcher\Event;

/**
 * Advanced Recorder for Codeception
 * by Mario Lubenka
 * ---
 * Based on the original Codeception Recorder
 */
class AdvancedRecorder extends \Codeception\Extension
{
	protected $config = [
		'delete_successful' => true,
		'module'            => 'WebDriver',
		'template'          => null,
		'animate_slides'    => true
	];
	
	protected $template = __DIR__.'/../Resources/Private/index_slides.html';
	protected $indicatorTemplate = __DIR__.'/../Resources/Private/Partials/indicator.html';
	protected $indexTemplate = __DIR__.'/../Resources/Private/index.html';
	protected $slidesTemplate = __DIR__.'/../Resources/Private/slides.html';
	protected $tableElementTemplate = __DIR__.'/../Resources/Private/Partials/tableElement.html';
	
	public static $events = [
		Events::SUITE_BEFORE => 'beforeSuite',
		Events::SUITE_AFTER  => 'afterSuite',
		Events::TEST_BEFORE  => 'before',
		Events::TEST_FAIL   => 'persist',
		Events::TEST_ERROR   => 'persist',
		Events::TEST_SKIPPED   => 'persist',
		Events::TEST_INCOMPLETE   => 'persist',
		Events::TEST_SUCCESS => 'cleanup',
		Events::STEP_AFTER   => 'afterStep',
	];
	
	/**
	 * @var WebDriver
	 */
	protected $webDriverModule;
	protected $dir;
	protected $recordDir;
	protected $slides = [];
	protected $stepNum = 0;
	protected $seed;
	protected $recordedTests = [];
	
	public function beforeSuite()
	{
		$this->webDriverModule = null;
		if (!$this->hasModule($this->config['module'])) {
			$this->writeln("Recorder is disabled, no available modules");
			return;
		}
		$this->seed = uniqid();
		$this->webDriverModule = $this->getModule($this->config['module']);
		if (!$this->webDriverModule instanceof ScreenshotSaver) {
			throw new ExtensionException(
				$this,
				'You should pass module which implements Codeception\Lib\Interfaces\ScreenshotSaver interface'
			);
		}
		$this->writeln(sprintf(
			"⏺ <bold>Recording</bold> ⏺ step-by-step screenshots will be saved to <info>%s</info>",
			codecept_output_dir()
		));
		$this->writeln("Directory Format: <debug>record_{$this->seed}/{testname}</debug> ----");
	}
	public function afterSuite() {
		if (!$this->webDriverModule or !$this->dir) {
			return;
		}
		$links = '';
		foreach ($this->recordedTests as $linkText => $testData) {
			$seconds = (int)($milliseconds = (int)($testData['time'] * 1000)) / 1000;
			$time = ($seconds % 60) . (($milliseconds === 0) ? '' : '.' . $milliseconds);
			
			if ($this->config['delete_successful'] && $testData['wasSuccessful']) {
				continue;
			}
			
			if(!empty($testData['wasSuccessful'])) {
				$status = 'successful';
				$trClass = 'success';
			}else{
				switch($testData['status']) {
					case Events::TEST_INCOMPLETE:
						$status = 'incomplete';
						$trClass = 'info';
						break;
					case Events::TEST_SKIPPED:
						$status = 'skipped';
						$trClass = 'warning';
						break;
					default:
						$status = 'failed';
						$trClass = 'danger';
						break;
				}
			}
			$links .= (new Template(file_get_contents($this->tableElementTemplate)))
				->place('status', $status)
				->place('url', $testData['url'])
				->place('trClass', $trClass)
				->place('linkText', $linkText)
				->place('executionTime', number_format($time, 2))
				->produce();
		}
		$indexHTML = (new Template(file_get_contents($this->indexTemplate)))
			->place('seed', $this->seed)
			->place('records', $links)
			->produce();
		
		file_put_contents(codecept_output_dir().'records.html', $indexHTML);
		file_put_contents($this->recordDir.'/records.html', $indexHTML);
		$this->writeln("⏺ Records saved into: <info>file://" . codecept_output_dir().'records.html</info>');
	}
	public function before(TestEvent $e)
	{
		if (!$this->webDriverModule) {
			return;
		}
		$this->dir = null;
		$this->stepNum = 0;
		$this->slides = [];
		$testName = preg_replace('~\W~', '.', Descriptor::getTestAsString($e->getTest()));
		
		$this->recordDir = codecept_output_dir() . 'record_'.$this->seed;
		if(!is_dir($this->recordDir)) {
			@mkdir($this->recordDir);
		}
		$this->dir = $this->recordDir.'/'.$testName;
		@mkdir($this->dir);
	}
	public function cleanup(TestEvent $e)
	{
		if (!$this->webDriverModule or !$this->dir) {
			return;
		}
		if (!$this->config['delete_successful']) {
			$this->persist($e);
			return;
		}
		
		// deleting successfully executed tests
		$testName = preg_replace('~\W~', '.', Descriptor::getTestAsString($e->getTest()));
		FileSystem::deleteDir($this->recordDir.'/'.$testName);
	}
	
	public function persist(TestEvent $e, $status='')
	{
		if (!$this->webDriverModule or !$this->dir) {
			return;
		}
		$indicatorHtml = '';
		$slideHtml = '';
		foreach ($this->slides as $i => $step) {
			/** @var Step $step */
			$indicatorHtml .= (new Template(file_get_contents($this->indicatorTemplate)))
				->place('step', (int)$i)
				->place('isActive', (int)$i ? '' : 'class="active"')
				->produce();
			
			$slideClass = '';
			if($step->hasFailed()) {
				$slideClass = 'error';
			}
			$slideHtml .= (new Template(file_get_contents($this->slidesTemplate)))
				->place('image', $i)
				->place('caption', $step->getHtml('#3498db'))
				->place('isActive', (int)$i ? '' : 'active')
				->place('slideClass', $slideClass)
				->produce();
		}
		
		$html = (new Template(file_get_contents($this->template)))
			->place('indicators', $indicatorHtml)
			->place('slides', $slideHtml)
			->place('feature', ucfirst($e->getTest()->getFeature()))
			->place('test', Descriptor::getTestSignature($e->getTest()))
			->place('carousel_class', $this->config['animate_slides'] ? ' slide' : '')
			->produce();
		
		$indexFile = $this->dir . DIRECTORY_SEPARATOR . 'index.html';
		file_put_contents($indexFile, $html);
		$testName = Descriptor::getTestSignature($e->getTest()). ' - '.ucfirst($e->getTest()->getFeature());
		
		/** @var \PHPUnit_Framework_TestResult $testResultObject */
		$testResultObject = $e->getTest()->getTestResultObject();
		
		$this->recordedTests[$testName] = array(
			'url' => substr($indexFile, strlen(codecept_output_dir())),
			'wasSuccessful' => $testResultObject->wasSuccessful(),
			'status' => $status,
			'time' => $e->getTime()
		);
	}
	
	public function afterStep(StepEvent $e)
	{
		if (!$this->webDriverModule or !$this->dir) {
			return;
		}
		if ($e->getStep() instanceof CommentStep) {
			return;
		}
		
		$filename = str_pad($this->stepNum, 3, "0", STR_PAD_LEFT) . '.png';
		$this->webDriverModule->_saveScreenshot($this->dir . DIRECTORY_SEPARATOR . $filename);
		$this->stepNum++;
		$this->slides[$filename] = $e->getStep();
	}
}
