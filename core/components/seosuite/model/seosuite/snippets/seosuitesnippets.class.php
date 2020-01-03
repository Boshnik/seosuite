<?php
require_once dirname(__DIR__) . '/seosuite.class.php';

/**
 * Class SeoSuiteSnippets.
 */
class SeoSuiteSnippets extends SeoSuite
{
    const PHS_PREFIX = 'ss_meta';

    /**
     * Snippet for outputting meta data.
     * @param $properties
     * @return string
     */
    public function seosuiteMeta($properties)
    {
        $id             = $this->modx->getOption('id', $properties, $this->modx->resource->get('id'));
        $tplTitle       = $this->modx->getOption('tplTitle', $properties, 'tplMetaTitle');
        $tpl            = $this->modx->getOption('tpl', $properties, 'tplMeta');
        $toPlaceholders = $this->modx->getOption('toPlaceholders', $properties, false);

        $meta = [
            'meta_title' => [
                'name'  => 'title',
                'value' => $this->config['meta']['default_meta_title'],
                'tpl'   => $tplTitle
            ],
            'meta_description' => [
                'name'  => 'description',
                'value' => $this->config['meta']['default_meta_description'],
                'tpl'   => $tpl
            ]
        ];

        $ssResource = $this->modx->getObject('SeoSuiteResource', $id);
        if ($ssResource) {
            foreach ($meta as $key => $values) {
                $meta[$key]['value'] = $ssResource->get($key);
            }
        }

        $resourceArray = [];
        if ($modResource = $this->modx->getObject('modResource', $id)) {
            $resourceArray = $modResource->toArray();
        }

        $html = [];
        foreach ($meta as $key => $item) {
            $tpl = $item['tpl'];

            /* Unset tpl from placeholders. */
            unset($item['tpl']);

            /* Parse JSON. */
            $item['value'] = $this->renderMetaValue($item['value'], $resourceArray);

            $rowHtml = $this->getChunk($tpl, $item);
            if ($toPlaceholders) {
                $this->modx->toPlaceholder($key, $rowHtml,self::PHS_PREFIX);
            } else {
                $html[] = $rowHtml;
            }
        }

        if ($toPlaceholders) {
            return '';
        }

        return implode(PHP_EOL, $html);
    }

    public function seosuiteSitemap(array $scriptProperties = [])
    {
        $allowSymlinks  = $this->modx->getOption('allowSymlinks', $scriptProperties, 0);
        $contexts       = $this->modx->getOption('contexts', $scriptProperties, null);
        $type           = $this->modx->getOption('type', $scriptProperties, '');
        $templates      = $this->modx->getOption('templates', $scriptProperties, '');
        $outerTpl       = $this->modx->getOption('outerTpl', $scriptProperties, 'sitemap/outertpl');
        $rowTpl         = $this->modx->getOption('rowTpl', $scriptProperties, 'sitemap/rowtpl');
        $alternateTpl   = $this->modx->getOption('alternateTpl', $scriptProperties, 'sitemap/alternatetpl');
        $indexOuterTpl  = $this->modx->getOption('indexOuterTpl', $scriptProperties, 'sitemap/index/outertpl');
        $indexRowTpl    = $this->modx->getOption('indexRowTpl', $scriptProperties, 'sitemap/index/rowtpl');
        $imagesOuterTpl = $this->modx->getOption('imageOuterTpl', $scriptProperties, 'sitemap/images/outertpl');
        $imagesRowTpl   = $this->modx->getOption('imagesRowTpl', $scriptProperties, 'sitemap/images/rowtpl');
        $imageTpl       = $this->modx->getOption('imageTpl', $scriptProperties, 'sitemap/images/imagetpl');

        /* Properly set contexts variable. */
        $contexts = $contexts ? explode(',', str_replace(' ', '', $contexts)) : [$this->modx->resource->get('context_key')];

        return $this->sitemap(
            $contexts,
            $allowSymlinks,
            [
                'outerTpl'       => $outerTpl,
                'rowTpl'         => $rowTpl,
                'alternateTpl'   => $alternateTpl,
                'type'           => $type,
                'indexOuterTpl'  => $indexOuterTpl,
                'indexRowTpl'    => $indexRowTpl,
                'imagesOuterTpl' => $imagesOuterTpl,
                'imagesRowTpl'   => $imagesRowTpl,
                'imageTpl'       => $imageTpl,
                'templates'      => $templates
            ]
        );
    }

    /**
     * Generate XML sitemap.
     *
     * @param array  $contextKey
     * @param string $allowSymlinks
     * @param array  $options
     *
     * @return string
     * @internal param string $type
     * @internal param string $templates
     *
     */
    protected function sitemap(array $contextKey = ['web'], $allowSymlinks = '', $options = [])
    {
        $outerTpl     = $options['outerTpl'];
        $rowTpl       = $options['rowTpl'];
        $query        = $this->buildQuery($contextKey, $allowSymlinks, $options);
        $rawResources = $this->modx->getCollection('SeoSuiteResource', $query);

        $resources = [];
        foreach ($rawResources as $modResource) {
            $resources[$modResource->get('id')] = $modResource;
        }

        if ($options['type'] === 'index') {
            $outerTpl = $options['indexOuterTpl'];
            $rowTpl   = $options['indexRowTpl'];
        }

        if ($options['type'] === 'images') {
            return $this->sitemapImages($contextKey, $resources, $options);
        }

        /* If resources should be displayed based upon parent/ultimate parent properties. */
        /**
         * @TODO REFACTOR BELOW
         */
        $sitemapDependsOnUltimateParent = (bool) $this->getOption('stercseo.xmlsitemap.dependent_ultimateparent', null, false);
        if ($sitemapDependsOnUltimateParent) {
            $resources = $this->filterResourcesByParentProperties($resources);
        }

        $output = [];
        foreach ($resources as $resource) {
            $lastmod = $this->getLastModTime($options['type'], $resource);

            /**
             * @TODO default changefreq
             * @TODO default priority
             */
            $output[] = $this->getChunk(
                $rowTpl,
                array_merge(
                    $resource->toArray(),
                    [
                        'url'        => $this->modx->makeUrl($resource->get('id'), '', '', 'full'),
                        'alternates' => $this->getAlternateLinks($resource, $options),
                        'lastmod'    => date('c', $lastmod),
                        'changefreq' => !empty($resource->get('SeoSuiteResource.sitemap_changefreq')) ? $resource->get('SeoSuiteResource.sitemap_changefreq') : $this->defaults['changefreq'],
                        'priority'   => !empty($resource->get('SeoSuiteResource.sitemap_prio')) ? $resource->get('SeoSuiteResource.sitemap_prio') : $this->defaults['priority'],
                    ]
                )
            );
        }

        return $this->getChunk($outerTpl, ['wrapper' => implode('', $output)]);
    }

    /**
     * Get last modification time for a sitemap type of a specific resource.
     *
     * @param $type
     * @param $resource
     *
     * @return int
     */
    protected function getLastModTime($type, $resource)
    {
        $lastmod = 0;
        if ($type === 'index') {
            $content = $resource->get('content');
            preg_match_all('/\[\[[^[]*]]/', $content, $matches);

            if (count($matches) > 0) {
                foreach ($matches as $match) {
                    $match = trim($match[0], '[]!');
                    if (0 === strpos($match, 'StercSeoSiteMap')) {
                        /* Get snippet parameter values. */
                        preg_match('/&type=`(.*)`/', $match, $type);
                        preg_match('/&templates=`(.*)`/', $match, $templates);
                        preg_match('/&allowSymlinks=`(.*)`/', $match, $allowSymlinks);
                        preg_match('/&contexts=`(.*)`/', $match, $contexts);

                        $type          = (isset($type[1])) ? $type[1] : '';
                        $allowSymlinks = (isset($allowSymlinks[1])) ? $allowSymlinks[1] : 0;
                        $contexts      = (isset($contexts[1])) ? explode(',',str_replace(' ', '', $contexts[1])) : array($this->modx->resource->get('context_key'));
                        $templates     = (isset($templates[1])) ? $templates[1] : '';

                        /* If the sitemap type is images, set the last mod time to current time. */
                        if ($type === 'images') {
                            $lastmod = time();
                            continue;
                        }

                        $query     = $this->buildQuery($contexts, $allowSymlinks, ['type' => $type, 'templates' => $templates]);
                        $resources = $this->modx->getIterator('modResource', $query);
                        if ($resources) {
                            foreach ($resources as $resource) {
                                $createdon       = $resource->get('createdon');
                                $editedon        = $resource->get('editedon');
                                $resourceLastmod = strtotime((($editedon > 0) ? $editedon : $createdon));

                                if ($resourceLastmod > $lastmod) {
                                    $lastmod = $resourceLastmod;
                                }
                            }
                        }
                    }
                }
            }
        } else {
            $editedon  = $resource->get('editedon');
            $createdon = $resource->get('createdon');
            $lastmod   = strtotime((($editedon > 0) ? $editedon : $createdon));
        }

        return $lastmod;
    }

    /**
     * Adds alternative language links to sitemap XML.
     *
     * @param $resource
     * @param $options
     * @return string
     */
    protected function getAlternateLinks($resource, $options)
    {
        /* Include current resource. */
        $babel = &$this->modx->getService(
            'babel',
            'Babel',
            $this->modx->getOption(
                'babel.core_path',
                null,
                $this->modx->getOption('core_path') . 'components/babel/'
            ) . 'model/babel/'
        );

        /**
         * @TODO REFACTOR BELOW.
         */
        /* Return if babel is not installed or the alternate links option is set to false or type is index or images. */
        if (!$babel ||
            (int) $this->modx->getOption('stercseo.xmlsitemap.babel.add_alternate_links') !== 1 ||
            (isset($options['type']) && in_array($options['type'], ['index', 'images'], true))
        ) {
            return '';
        }

        $alternates   = [];
        $translations = $babel->getLinkedResources($resource->get('id'));
        foreach ($translations as $contextKey => $resourceId) {
            $this->modx->switchContext($contextKey);
            $alternates[] = $this->getChunk(
                $options['alternateTpl'], [
                    'cultureKey' => $this->modx->getOption('cultureKey', ['context_key' => $contextKey], 'en'),
                    'url'        => $this->modx->makeUrl($resourceId, '', '', 'full')
                ]
            );
        }
        return implode(PHP_EOL, $alternates);
    }

    /**
     * Generate sitemap for images.
     *
     * @param $contextKey
     * @param $resources
     * @param $options
     *
     * @return string
     */
    public function sitemapImages($contextKey, $resources, $options)
    {
        $usedMediaSourceIds = [];
        $resourceIds        = [];
        if ($resources) {
            foreach ($resources as $resource) {
                $resourceIds[] = $resource->get('id');
            }
        }

        /* Get all image tvs of the retrieved resources and return all image tv's chained to resource. */
        $q = $this->modx->newQuery('modTemplateVar');
        $q->select('modTemplateVar.*, Value.*');
        $q->leftJoin('modTemplateVarResource', 'Value', array('modTemplateVar.id = Value.tmplvarid'));
        $q->where(
            array(
                'Value.contentid:IN'     => $resourceIds,
                'Value.value:!='         => '',
                'modTemplateVar.type:IN' => array('image','migx')
            )
        );

        $imageTVs = $this->modx->getIterator('modTemplateVar', $q);
        if ($imageTVs) {
            $q = $this->modx->newQuery('sources.modMediaSourceElement');
            $q->where(
                array(
                    'object_class'   => 'modTemplateVar',
                    'context_key:IN' => $contextKey
                )
            );
            $getTVSources = $this->modx->getIterator('sources.modMediaSourceElement', $q);
            $tvSources    = array();
            if ($getTVSources) {
                foreach ($getTVSources as $tvSource) {
                    $tvSources[$tvSource->get('object')] = $tvSource->get('source');
                }
            }
            foreach ($imageTVs as $imageTV) {
                $imageTV = $imageTV->toArray();
                $cid     = $imageTV['contentid'];
                if ($imageTV['type'] === 'migx') {
                    $this->getImagesValuesFromMIGX($cid, $imageTV, $tvSources);
                } else {
                    $this->images[$cid][] = array(
                        'id'     => $imageTV['id'],
                        'value'  => $imageTV['value'],
                        'source' => $tvSources[$imageTV['tmplvarid']]
                    );
                }
                /* Store used mediasource ID's in an array. */
                if (!in_array($tvSources[$imageTV['tmplvarid']], $usedMediaSourceIds)) {
                    $usedMediaSourceIds[] = $tvSources[$imageTV['tmplvarid']];
                }
            }
        }
        $output = '';
        if ($resources) {
            $mediasources = [];
            if (count($usedMediaSourceIds) > 0) {
                foreach ($usedMediaSourceIds as $mediaSourceId) {
                    $this->modx->loadClass('sources.modMediaSource');
                    $source = modMediaSource::getDefaultSource($this->modx, $mediaSourceId, false);
                    if ($source) {
                        $source->initialize();
                        /*
                         * CDN TV's are saved with full path, therefore only set full path for modFileMediaSource image tv types.
                         */
                        $url = ($source->get('class_key') === 'sources.modFileMediaSource') ? rtrim(MODX_SITE_URL, '/') . '/' . ltrim($source->getBaseUrl(), '/') : '';
                        $mediasources[$mediaSourceId] = array_merge(array('full_url' => $url), $source->toArray());
                    }
                }
            }
            foreach ($resources as $resource) {
                $imagesOutput = '';
                if (isset($this->images[$resource->get('id')])) {
                    foreach ($this->images[$resource->get('id')] as $image) {
                        /* Set correct full url for image based on context and mediasource. */
                        $image = $this->setImageUrl($mediasources, $image);
                        $imagesOutput .= $this->getChunk($options['imageTpl'], array(
                            'url' => $image['value']
                        ));
                    }
                    $output .= $this->getChunk($options['imagesRowTpl'], array(
                        'url'    => $this->modx->makeUrl($resource->get('id'), '', '', 'full'),
                        'images' => $imagesOutput
                    ));
                }
            }
        }
        return $this->getChunk($options['imagesOuterTpl'], array('wrapper' => $output));
    }

    /**
     * @param $resources
     * @return mixed
     */
    protected function filterResourcesByParentProperties($resources)
    {
        foreach ($resources as $resourceId => $resource) {
            if ($resource->get('parent') > 0) {
                if (!array_key_exists($resource->get('parent'), $resources)) {
                    unset($resources[$resource->get('id')]);
                }
            }
        }

        return $resources;
    }

    /**
     * Build query to retrieve resources.
     *
     * @param $contextKey
     * @param $allowSymlinks
     * @param $options
     *
     * @return mixed
     */
    protected function buildQuery($contextKey, $allowSymlinks, $options)
    {
        $query = $this->modx->newQuery('SeoSuiteResource');
        $query->innerJoin('modResource', 'modResource', 'SeoSuiteResource.resource_id = modResource.id');

        $query->select(
            [
                'modResource.*',
                $this->modx->getSelectColumns(
                    'SeoSuiteResource',
                    'SeoSuiteResource',
                    'SeoSuiteResource.',
                    array_keys($this->modx->getFields('SeoSuiteResource'))
                )
            ]
        );

        $query->where([
            [
                'modResource.context_key:IN' => $contextKey,
                'modResource.published'      => 1,
                'modResource.deleted'        => 0
            ]
        ]);

        /* Exclude pages with noindex and nofollow. */
        $query->where([
            'SeoSuiteResource.index_type'  => 1,
            'SeoSuiteResource.follow_type' => 1
        ]);

        if ($options['type'] !== 'index') {
            $query->where([
                'SeoSuiteResource.sitemap' => true
            ]);
        }

        if (!$allowSymlinks) {
            $query->where(['modResource.class_key:!=' => 'modSymLink']);
        }

        if ($options['type'] === 'index') {
            $parent = $this->modx->resource->get('id');
            $query->where(['modResource.parent' => $parent]);
        }

        if (!empty($options['templates'])) {
            $notAllowedTemplates = [];
            $allowedTemplates    = [];
            $this->parseTemplatesParam($options['templates'], $notAllowedTemplates, $allowedTemplates);

            if (count($notAllowedTemplates) > 0) {
                $query->where(['modResource.template:NOT IN' => $notAllowedTemplates]);
            }

            if (count($allowedTemplates) > 0) {
                $query->where(['modResource.template:IN' => $allowedTemplates]);
            }
        }

        return $query;
    }

    /**
     * Parse templates parameter and set allowed and non-allowed templates as arrays.
     *
     * @param $templates
     * @param $notAllowedTemplates
     * @param $allowedTemplates
     */
    protected function parseTemplatesParam($templates, &$notAllowedTemplates, &$allowedTemplates)
    {
        $templates = explode(',', $templates);
        foreach ($templates as $template) {
            $template = trim($template, ' ');
            $char     = substr($template, 0, 1);
            if ($char === '-') {
                $notAllowedTemplates[] = trim($template, '-');
            } else {
                $allowedTemplates[] = $template;
            }
        }
    }
}
