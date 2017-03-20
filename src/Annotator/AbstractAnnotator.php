<?php
namespace IdeHelper\Annotator;

use Bake\View\Helper\DocBlockHelper;
use Cake\Console\Shell;
use Cake\Core\App;
use Cake\Core\InstanceConfigTrait;
use Cake\View\View;
use IdeHelper\Annotation\AbstractAnnotation;
use IdeHelper\Annotation\AnnotationFactory;
use IdeHelper\Console\Io;
use PHP_CodeSniffer\Config;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Fixer;
use PHP_CodeSniffer\Reporter;
use PHP_CodeSniffer\Ruleset;
use PHP_CodeSniffer\Runner;
use PHP_CodeSniffer\Util\Tokens;
use ReflectionClass;
use SebastianBergmann\Diff\Differ;

//if (class_exists('')) {
//}

abstract class AbstractAnnotator {

	use InstanceConfigTrait;

	const CONFIG_DRY_RUN = 'dry-run';
	const CONFIG_PLUGIN = 'plugin';
	const CONFIG_NAMESPACE = 'namespace';
	const CONFIG_VERBOSE = 'verbose';

	/**
	 * @var bool
	 */
	public static $output = false;

	/**
	 * @var \Cake\Console\ConsoleIo
	 */
	protected $_io;

	/**
	 * @var array
	 */
	protected $_defaultConfig = [
		self::CONFIG_PLUGIN => null,
	];

	/**
	 * @param \IdeHelper\Console\Io $io
	 * @param array $config
	 */
	public function __construct(Io $io, array $config) {
		$this->_io = $io;
		$this->setConfig($config);

		$namespace = $this->getConfig(static::CONFIG_PLUGIN) ?: 'App';
		$namespace = str_replace('/', '\\', $namespace);
		$this->setConfig(static::CONFIG_NAMESPACE, $namespace);
	}

	/**
	 * @param string $path Path to file.
	 * @return bool
	 */
	abstract public function annotate($path);

	/**
	 * @param string $file
	 *
	 * @return File
	 */
	protected function _getFile($file) {
		$_SERVER['argv'] = [];

		//$phpcs = new Runner();

		//define('PHP_CODESNIFFER_CBF', false);
		$config = new Config();
		//$phpcs->config = $config;
		//$phpcs->config->standards = [$this->root . static::STANDARD];
		//$phpcs->init();
		//$phpcs->reporter = new Reporter($config);
		$ruleset = new Ruleset($config);

		//$phpcs = new Runner();
		//$phpcs->process([], null, []);
		return new File($file, $ruleset, $config);
	}

	/**
	 * @param string $oldContent
	 * @param string $newContent
	 * @return void
	 */
	protected function _displayDiff($oldContent, $newContent) {
		if (!$this->getConfig(static::CONFIG_VERBOSE)) {
			return;
		}

		$differ = new Differ(null);
		$array = $differ->diffToArray($oldContent, $newContent);

		$begin = null;
		$end = null;
		foreach ($array as $key => $row) {
			if ($row[1] === 0) {
				continue;
			}

			if ($begin === null) {
				$begin = $key;
			}
			$end = $key;
		}
		if ($begin === null) {
			return;
		}
		$firstLineOfOutput = $begin > 0 ? $begin - 1 : 0;
		$lastLineOfOutput = count($array) - 1 > $end ? $end + 1 : $end;

		for ($i = $firstLineOfOutput; $i <= $lastLineOfOutput; $i++) {
			$row = $array[$i];
			$char = ' ';
			if ($row[1] === 1) {
				$char = '+';
				$this->_io->info('   | ' . $char . $row[0], 1, Shell::VERBOSE);
			} elseif ($row[1] === 2) {
				$char = '-';
				$this->_io->out('<warning>' . '   | ' . $char . $row[0] . '</warning>', 1);
			} else {
				$this->_io->out('   | ' . $char . $row[0], 1, Shell::VERBOSE);
			}
		}
	}

	/**
	 * @param string $path
	 * @param string $contents
	 * @return void
	 */
	protected function _storeFile($path, $contents) {
		static::$output = true;

		if ($this->getConfig(static::CONFIG_DRY_RUN)) {
			return;
		}
		file_put_contents($path, $contents);
	}

	/**
	 * @return Fixer
	 */
	protected function _getFixer(File $file) {
		return new Fixer($file);
	}

	/**
	 * @param string $path
	 * @param string $content
	 * @param array $annotations
	 *
	 * @return bool
	 */
	protected function _annotate($path, $content, array $annotations) {
		if (!$annotations) {
			return false;
		}

		$file = $this->_getFile($path);
		//$file->start($content);

		$classIndex = $file->findNext(T_CLASS, 0);

		$prevCode = $file->findPrevious(Tokens::$emptyTokens, $classIndex - 1, null, true);

		$closeTagIndex = $file->findPrevious(T_DOC_COMMENT_CLOSE_TAG, $classIndex - 1, $prevCode);
		if ($closeTagIndex) {
			$newContent = $this->_appendToExistingDocBlock($file, $closeTagIndex, $annotations);
		} else {
			$newContent = $this->_addNewDocBlock($file, $classIndex, $annotations);
		}

		$this->_displayDiff($content, $newContent);
		$this->_storeFile($path, $newContent);

		if (count($annotations)) {
			$this->_io->success('   -> ' . count($annotations) . ' annotations added');
		} else {
			$this->_io->verbose('   -> ' . count($annotations) . ' annotations added');
		}

		return true;
	}

	/**
	 * @param File $file
	 * @param int $closeTagIndex
	 * @param array $annotations
	 *
	 * @return string
	 */
	protected function _appendToExistingDocBlock(File $file, $closeTagIndex, $annotations) {
		$existingAnnotations = $this->_parseExistingAnnotations($file, $closeTagIndex);

		/* @var \IdeHelper\Annotation\AbstractAnnotation[] $replacingAnnotations */
		$replacingAnnotations = [];
		foreach ($annotations as $key => $annotation) {
			if (!is_object($annotation)) {
				continue;
			}
			$toBeReplaced = $this->_needsReplacing($annotation, $existingAnnotations);
			if (!$toBeReplaced) {
				continue;
			}

			$replacingAnnotations[] = $toBeReplaced;
			unset($annotations[$key]);
		}

		$tokens = $file->getTokens();

		$lastTagIndexOfPreviousLine = $closeTagIndex;
		while ($tokens[$lastTagIndexOfPreviousLine]['line'] === $tokens[$closeTagIndex]['line']) {
			$lastTagIndexOfPreviousLine--;
		}

		$needsNewline = $this->_needsNewLineInDocBlock($file, $lastTagIndexOfPreviousLine);

		$fixer = $this->_getFixer($file);
		$fixer->startFile($file);

		$fixer->beginChangeset();

		foreach ($replacingAnnotations as $annotation) {
			$fixer->replaceToken($annotation->getIndex(), $annotation->build());
		}

		if ($annotations) {
			$annotationString = $needsNewline ? ' *' . "\n" : '';
			foreach ($annotations as $annotation) {
				$annotationString .= ' * ' . $annotation . "\n";
			}

			$fixer->addContent($lastTagIndexOfPreviousLine, $annotationString);
		}

		$fixer->endChangeset();

		$contents = $fixer->getContents();

		return $contents;
	}

	/**
	 * @param \IdeHelper\Annotation\AbstractAnnotation $annotation
	 * @param \IdeHelper\Annotation\AbstractAnnotation[] $existingAnnotations
	 * @return \IdeHelper\Annotation\AbstractAnnotation|null
	 */
	protected function _needsReplacing(AbstractAnnotation $annotation, array $existingAnnotations) {
		foreach ($existingAnnotations as $existingAnnotation) {
			if ($existingAnnotation->matches($annotation)) {
				$existingAnnotation->replaceWith($annotation);
				return $existingAnnotation;
			}
		}

		return null;
	}

	/**
	 * @param File $file
	 * @param int $closeTagIndex
	 *
	 * @return array
	 */
	protected function _parseExistingAnnotations(File $file, $closeTagIndex) {
		$tokens = $file->getTokens();

		$startTagIndex = $tokens[$closeTagIndex]['comment_opener'];

		$docBlockParams = [];
		for ($i = $startTagIndex + 1; $i < $closeTagIndex; $i++) {
			if ($tokens[$i]['type'] !== 'T_DOC_COMMENT_TAG') {
				continue;
			}
			if (!in_array($tokens[$i]['content'], ['@param', '@var', '@method'])) {
				continue;
			}

			$classNameIndex = $i + 2;

			if ($tokens[$classNameIndex]['type'] !== 'T_DOC_COMMENT_STRING') {
				continue;
			}

			$content = $tokens[$classNameIndex]['content'];

			$appendix = '';
			$spacePos = strpos($content, ' ');
			if ($spacePos) {
				$appendix = substr($content, $spacePos);
				$content = substr($content, 0, $spacePos);
			}

			$docBlockParams[] = AnnotationFactory::create($tokens[$i]['content'], $content, trim($appendix), $classNameIndex);
		}

		return $docBlockParams;
	}

	/**
 * @param \File $file
 * @param int $lastTagIndexOfPreviousLine
 *
 * @return bool
 */
	protected function _needsNewLineInDocBlock(File $file, $lastTagIndexOfPreviousLine) {
		$tokens = $file->getTokens();

		$line = $tokens[$lastTagIndexOfPreviousLine]['line'];
		$index = $lastTagIndexOfPreviousLine - 1;
		while ($tokens[$index]['line'] === $line) {
			if ($tokens[$index]['code'] === T_DOC_COMMENT_TAG || $tokens[$index]['code'] === T_DOC_COMMENT_OPEN_TAG) {
				return false;
			}
			$index--;
		}

		return true;
	}

	/**
	 * @param File $file
	 * @param string $classIndex
	 * @param array $annotations
	 *
	 * @return string
	 */
	protected function _addNewDocBlock(File $file, $classIndex, array $annotations) {
		$tokens = $file->getTokens();

		foreach ($annotations as $key => $annotation) {
			if (is_string($annotation)) {
				continue;
			}
			$annotations[$key] = (string)$annotation;
		}

		$helper = new DocBlockHelper(new View());
		$annotationString = $helper->classDescription('', '', $annotations);

		$fixer = $this->_getFixer($file);
		$fixer->startFile($file);

		$docBlock = $annotationString . PHP_EOL;
		$fixer->replaceToken($classIndex, $docBlock . $tokens[$classIndex]['content']);

		$contents = $fixer->getContents();

		return $contents;
	}

	/**
	 * @param array $usedModels
	 * @param string $content
	 * @return array
	 */
	protected function _getModelAnnotations($usedModels, $content) {
		$annotations = [];

		foreach ($usedModels as $usedModel) {
			$className = App::className($usedModel, 'Model/Table', 'Table');
			if (!$className) {
				continue;
			}
			list(, $name) = pluginSplit($usedModel);

			$annotation = '@property \\' . $className . ' $' . $name;
			if (preg_match('/' . preg_quote($annotation) . '/', $content)) {
				continue;
			}

			$annotations[] = $annotation;
		}

		return $annotations;
	}

	/**
	 * Gets protected/private property of a class.
	 *
	 * So
	 *   $this->invokeProperty($object, '_foo');
	 * is equal to
	 *   $object->_foo
	 * (assuming the property was directly publicly accessible)
	 *
	 * @param object &$object Instantiated object that we want the property off.
	 * @param string $name Property name to fetch.
	 *
	 * @return mixed Property value.
	 */
	protected function _invokeProperty(&$object, $name) {
		$reflection = new ReflectionClass(get_class($object));
		$property = $reflection->getProperty($name);
		$property->setAccessible(true);

		return $property->getValue($object);
	}

}
