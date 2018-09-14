<?php
/**
 * Created by PhpStorm.
 * User: vincenzoruffa
 * Date: 13/09/2018
 * Time: 16:16
 */

namespace API\V3\Json;

use API\App\Json\OutsourceConfirmation;
use API\V2\Json\JobTranslator;
use API\V2\Json\ProjectUrls;
use CatUtils;
use Chunks_ChunkStruct;
use DataAccess\ShapelessConcreteStruct;
use Features\ReviewExtended;
use Features\ReviewImproved;
use Langs_Languages;
use Langs_LanguageDomains;
use LQA\ChunkReviewDao;
use ManageUtils;
use Routes;
use TmKeyManagement_ClientTmKeyStruct;
use Users_UserStruct;
use Utils;
use WordCount_Struct;
use FeatureSet;

class Chunk extends \API\V2\Json\Chunk {

    /**
     * @param \Chunks_ChunkStruct $chunk
     *
     * @return array
     * @throws \Exception
     * @throws \Exceptions\NotFoundError
     */
    public function renderOne( \Chunks_ChunkStruct $chunk ) {
        return [
                'job' => [
                        'id'     => (int)$chunk->id,
                        'chunks' => [ $this->renderItem( $chunk ) ]
                ]
        ];
    }

    /**
     * @param $jStruct Chunks_ChunkStruct
     *
     * @return array
     * @throws \Exception
     * @throws \Exceptions\NotFoundError
     */
    public function renderItem( Chunks_ChunkStruct $jStruct ) {

        $featureSet = new FeatureSet();

        $outsourceInfo = $jStruct->getOutsource();
        $tStruct       = $jStruct->getTranslator();
        $outsource     = null;
        $translator    = null;
        if ( !empty( $outsourceInfo ) ) {
            $outsource = ( new OutsourceConfirmation( $outsourceInfo ) )->render();
        } else {
            $translator = ( !empty( $tStruct ) ? ( new JobTranslator() )->renderItem( $tStruct ) : null );
        }

        $jobStats = new WordCount_Struct();
        $jobStats->setIdJob( $jStruct->id );
        $jobStats->setDraftWords( $jStruct->draft_words + $jStruct->new_words ); // (draft_words + new_words) AS DRAFT
        $jobStats->setRejectedWords( $jStruct->rejected_words );
        $jobStats->setTranslatedWords( $jStruct->translated_words );
        $jobStats->setApprovedWords( $jStruct->approved_words );

        $lang_handler = Langs_Languages::getInstance();

        $subject_handler = Langs_LanguageDomains::getInstance();
        $subjects        = $subject_handler->getEnabledDomains();

        $subjects_keys = Utils::array_column( $subjects, "key" );
        $subject_key   = array_search( $jStruct->subject, $subjects_keys );

        $warningsCount = $jStruct->getWarningsCount();

        $featureSet->loadForProject( $jStruct->getJob()->getProject() );

        if ( in_array( ReviewImproved::FEATURE_CODE, $featureSet->getCodes() ) || in_array( ReviewExtended::FEATURE_CODE, $featureSet->getCodes() ) ) {
            $reviseIssues     = [];
            $qualityReportDao = new ReviewImproved\Model\QualityReportDao();
            $qa_data          = $qualityReportDao->getReviseIssuesByJob( $jStruct->id );
            foreach ( $qa_data as $issue ) {
                if ( !isset( $reviseIssues[ $issue->id_category ] ) ) {
                    $reviseIssues[ $issue->id_category ] = [
                            'name'   => $issue->issue_category_label,
                            'founds' => [
                                    $issue->issue_severity => 1
                            ]
                    ];
                } else {
                    if ( !isset( $reviseIssues[ $issue->id_category ][ 'founds' ][ $issue->issue_severity ] ) ) {
                        $reviseIssues[ $issue->id_category ][ 'founds' ][ $issue->issue_severity ] = 1;
                    } else {
                        $reviseIssues[ $issue->id_category ][ 'founds' ][ $issue->issue_severity ]++;
                    }
                }
            }

        } else {

            $reviseClass = new \Constants_Revise();

            $jobQA = new \Revise_JobQA(
                    $jStruct->id,
                    $jStruct->password,
                    $jobStats->getTotal(),
                    $reviseClass
            );

            list( $jobQA, $reviseClass ) = $featureSet->filter( "overrideReviseJobQA", [ $jobQA, $reviseClass ], $jStruct->id,
                    $jStruct->password,
                    $jobStats->getTotal() );

            /**
             * @var $jobQA \Revise_JobQA
             */
            $jobQA->retrieveJobErrorTotals();
            $jobQA->evalJobVote();
            $qa_data = $jobQA->getQaData();

            $reviseIssues = [];
            foreach ( $qa_data as $issue ) {
                $reviseIssues[ str_replace( " ", "_", strtolower( $issue[ 'type' ] ) ) ] = [
                        'allowed' => $issue[ 'allowed' ],
                        'found'   => $issue[ 'found' ],
                        'founds'  => $issue[ 'founds' ]
                ];
            }
        }

        $result = [
                'id'                      => (int)$jStruct->id,
                'password'                => $jStruct->password,
                'source'                  => $jStruct->source,
                'target'                  => $jStruct->target,
                'sourceTxt'               => $lang_handler->getLocalizedName( $jStruct->source ),
                'targetTxt'               => $lang_handler->getLocalizedName( $jStruct->target ),
                'status'                  => $jStruct->status_owner,
                'subject'                 => $jStruct->subject,
                'subject_printable'       => $subjects[ $subject_key ][ 'display' ],
                'owner'                   => $jStruct->owner,
                'total_time_to_edit'      => $jStruct->total_time_to_edit,
                'avg_post_editing_effort' => $jStruct->avg_post_editing_effort,
                'open_threads_count'      => (int)$jStruct->getOpenThreadsCount(),
                'create_timestamp'        => strtotime( $jStruct->create_date ),
                'created_at'              => Utils::api_timestamp( $jStruct->create_date ),
                'create_date'             => $jStruct->create_date,
                'formatted_create_date'   => ManageUtils::formatJobDate( $jStruct->create_date ),
                'quality_overall'         => CatUtils::getQualityOverallFromJobStruct( $jStruct ),
                'pee'                     => $jStruct->getPeeForTranslatedSegments(),
                'private_tm_key'          => $this->getKeyList( $jStruct ),
                'warnings_count'          => $warningsCount->warnings_count,
                'warning_segments'        => ( isset( $warningsCount->warning_segments ) ? $warningsCount->warning_segments : [] ),
                'stats'                   => CatUtils::getFastStatsForJob( $jobStats, false ),
                'outsource'               => $outsource,
                'translator'              => $translator,
                'total_raw_wc'            => (int)$jStruct->total_raw_wc,
                'quality_summary'         => [
                        'equivalent_class' => $jStruct->getQualityInfo(),
                        'quality_overall'  => $jStruct->getQualityOverall(),
                        'errors_count'     => (int)$jStruct->getErrorsCount(),
                        'revise_issues'    => $reviseIssues
                ],

        ];


        $project = $jStruct->getProject();

        /**
         * @var $projectData ShapelessConcreteStruct[]
         */
        $projectData = ( new \Projects_ProjectDao() )->setCacheTTL( 60 * 60 * 24 )->getProjectData( $project->id, $project->password );

        $formatted = new ProjectUrls( $projectData );

        /** @var $formatted ProjectUrls */
        $formatted = $project->getFeatures()->filter( 'projectUrls', $formatted );

        $urlsObject       = $formatted->render( true );
        $result[ 'urls' ] = $urlsObject[ 'jobs' ][ $jStruct->id ][ 'chunks' ][ $jStruct->password ];

        $result[ 'urls' ][ 'original_download_url' ]    = $urlsObject[ 'jobs' ][ $jStruct->id ][ 'original_download_url' ];
        $result[ 'urls' ][ 'translation_download_url' ] = $urlsObject[ 'jobs' ][ $jStruct->id ][ 'translation_download_url' ];
        $result[ 'urls' ][ 'xliff_download_url' ]       = $urlsObject[ 'jobs' ][ $jStruct->id ][ 'xliff_download_url' ];

        return $result;

    }

}