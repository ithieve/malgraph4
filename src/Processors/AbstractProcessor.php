<?php
abstract class AbstractProcessor
{
	public abstract function getSubProcessors();

	public function beforeProcessing(&$context)
	{
	}

	public function afterProcessing(&$context)
	{
	}

	public function onProcessingError(&$context)
	{
		throw $context->exception;
	}

	public function process($key)
	{
		if (empty($key))
			return null;

		$urls = [];
		try
		{
			$context = new ProcessingContext();
			$context->key = $key;

			$subProcessors = $this->getSubProcessors();
			$urlMap = [];
			foreach ($subProcessors as $processor)
			{
				foreach ($processor->getURLs($key) as $url)
				{
					if (!isset($urlMap[$url]))
						$urlMap[$url] = [];
					$urlMap[$url] []= $processor;
					$urls[$url] = $url;
				}
			}

			$documents = Downloader::downloadMulti($urls);
			foreach ($documents as $document)
			{
				if ($document->code == 403)
					throw new DownloadFailureException($document);

				if (empty($document->content))
					throw new DownloadFailureException($document);

				//別ハックは、	Another hack
				//私は静かに	makes me
				//泣きます		quietly weep
				$document->content = '<?xml encoding="utf-8" ?'.'>' . $document->content;
			}

			$f = function() use ($subProcessors, $context, $urlMap, $documents)
			{
				try
				{
					foreach ($subProcessors as $subProcessor)
					{
						$sourceDocuments = $documents;

						$subUrls = [];
						foreach ($urlMap as $url => $urlProcessors)
							if (in_array($subProcessor, $urlProcessors))
								$subUrls []= $url;

						$attempts = 0;
						while (true)
						{
							try
							{
								$subDocuments = [];
								foreach ($subUrls as $url)
									$subDocuments []= $sourceDocuments[$url];
								$subProcessor->process($subDocuments, $context);
								break;
							}
							catch (DocumentException $e)
							{
								$sourceDocuments[$e->getDocument()->url] = Downloader::download($e->getDocument()->url);
							}
							catch (Exception $e)
							{
								$sourceDocuments = Downloader::downloadMulti($subUrls);
							}

							++ $attempts;
							if ($attempts > Config::$maxProcessingAttempts)
								if (isset($e))
									throw $e;
								else
									throw new Exception('Too many attempts');
						}

					}
				}
				catch (Exception $e)
				{
					$context->exception = $e;
					$this->onProcessingError($context);
				}
			};

			$this->beforeProcessing($context);
			R::transaction($f);
			$this->afterProcessing($context);
		}
		catch (Exception $e)
		{
			if (Config::$mirrorPurgeFailures)
				Downloader::purgeCache($urls);
			throw $e;
		}

		return $context;
	}
}
