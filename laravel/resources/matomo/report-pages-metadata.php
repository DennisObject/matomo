<?php

declare(strict_types=1);

return [
    0 => [
        'uniqueId' => 'General_AIAssistants.AIAgents_AIAgentsOverview',
        'category' => [
            'id' => 'General_AIAssistants',
            'name' => [
                'translationKey' => 'General_AIAssistants',
            ],
            'order' => '80',
            'icon' => 'icon-ai-assistants',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
                1 => 'CoreHome_AIInsights',
            ],
        ],
        'subcategory' => [
            'id' => 'AIAgents_AIAgentsOverview',
            'name' => [
                'translationKey' => 'AIAgents_AIAgentsOverview',
            ],
            'order' => '20',
            'help' => '<p>Review how AI agents and human visitors engage with your site at a glance. This overview surfaces combined metrics and trends so you can quickly spot changes before exploring detailed reports.</p>',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'AIAgents_WidgetGraphAIAgents',
                ],
                'module' => 'AIAgents',
                'action' => 'getEvolutionGraph',
                'order' => '1',
                'parameters' => [
                    'forceView' => '1',
                    'viewDataTable' => 'graphEvolution',
                    'module' => 'AIAgents',
                    'action' => 'getEvolutionGraph',
                ],
                'uniqueId' => 'widgetAIAgentsgetEvolutionGraphforceView1viewDataTablegraphEvolution',
                'isWide' => '0',
                'viewDataTable' => 'graphEvolution',
                'isReport' => '1',
            ],
            1 => [
                'name' => [
                    'translationKey' => 'AIAgents_AIAgentsOverview',
                ],
                'module' => 'AIAgents',
                'action' => 'get',
                'order' => '2',
                'parameters' => [
                    'forceView' => '1',
                    'viewDataTable' => 'sparklines',
                    'module' => 'AIAgents',
                    'action' => 'get',
                ],
                'uniqueId' => 'widgetAIAgentsgetforceView1viewDataTablesparklines',
                'isWide' => '0',
                'viewDataTable' => 'sparklines',
                'isReport' => '1',
            ],
        ],
    ],
    1 => [
        'uniqueId' => 'General_AIAssistants.BotTracking_AIChatbotsContentRequests',
        'category' => [
            'id' => 'General_AIAssistants',
            'name' => [
                'translationKey' => 'General_AIAssistants',
            ],
            'order' => '80',
            'icon' => 'icon-ai-assistants',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
                1 => 'CoreHome_AIInsights',
            ],
        ],
        'subcategory' => [
            'id' => 'BotTracking_AIChatbotsContentRequests',
            'name' => [
                'translationKey' => 'BotTracking_AIChatbotsContentRequests',
            ],
            'order' => '15',
            'help' => '<p>This reporting page shows which pages and documents on your website have been requested by AI chatbots such as ChatGPT and similar large language model–based bots. These reports detail which HTML pages and downloadable files (like PDFs, Word, or Excel documents) were accessed, along with those that could not be retrieved due to errors. This helps you understand which content AI systems are referencing, identify broken or inaccessible resources, and ensure your website provides reliable access to information used by AI-driven tools.</p><p>It’s important to note that none of these pages or documents were actually viewed by humans in the traditional way — all requests originate from AI assistants fetching content automatically.</p><p>Currently, these reports only include requests from AI bots that do not execute JavaScript, and exclude traffic from AI crawlers used for model training or AI agents capable of running JavaScript.</p>',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'BotTracking_NoRecentRequestsWidgetTitle',
                ],
                'module' => 'BotTracking',
                'action' => 'noRecentRequestsMessageContentRequests',
                'order' => '0',
                'parameters' => [
                    'module' => 'BotTracking',
                    'action' => 'noRecentRequestsMessageContentRequests',
                ],
                'uniqueId' => 'widgetBotTrackingnoRecentRequestsMessageContentRequests',
                'isWide' => '1',
                'middlewareParameters' => [
                    'module' => 'BotTracking',
                    'action' => 'showNoRecentRequestsMessage',
                ],
                'clientComponent' => [
                    'plugin' => 'BotTracking',
                    'name' => 'NoRecentRequestsWidget',
                ],
            ],
            1 => [
                'name' => [
                    'translationKey' => 'General_Pages',
                ],
                'module' => 'BotTracking',
                'action' => 'getAIChatbotContentPages',
                'order' => '110',
                'parameters' => [
                    'module' => 'BotTracking',
                    'action' => 'getAIChatbotContentPages',
                ],
                'uniqueId' => 'widgetBotTrackinggetAIChatbotContentPages',
                'isWide' => '1',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
            2 => [
                'name' => [
                    'translationKey' => 'BotTracking_AIChatbotsContentDocumentsTitle',
                ],
                'module' => 'BotTracking',
                'action' => 'getAIChatbotContentDocuments',
                'order' => '120',
                'parameters' => [
                    'module' => 'BotTracking',
                    'action' => 'getAIChatbotContentDocuments',
                ],
                'uniqueId' => 'widgetBotTrackinggetAIChatbotContentDocuments',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
            3 => [
                'name' => [
                    'translationKey' => 'BotTracking_AIChatbotsBrokenContentTitle',
                ],
                'module' => 'BotTracking',
                'action' => 'getAIChatbotBrokenContent',
                'order' => '130',
                'parameters' => [
                    'module' => 'BotTracking',
                    'action' => 'getAIChatbotBrokenContent',
                ],
                'uniqueId' => 'widgetBotTrackinggetAIChatbotBrokenContent',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
            4 => [
                'name' => [
                    'translationKey' => 'BotTracking_AIChatbotsHumanFavouredPagesTitle',
                ],
                'module' => 'BotTracking',
                'action' => 'getAIChatbotHumanFavouredPages',
                'order' => '140',
                'parameters' => [
                    'module' => 'BotTracking',
                    'action' => 'getAIChatbotHumanFavouredPages',
                ],
                'uniqueId' => 'widgetBotTrackinggetAIChatbotHumanFavouredPages',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
            5 => [
                'name' => [
                    'translationKey' => 'BotTracking_AIChatbotsAIFavouredPagesTitle',
                ],
                'module' => 'BotTracking',
                'action' => 'getAIChatbotAIFavouredPages',
                'order' => '150',
                'parameters' => [
                    'module' => 'BotTracking',
                    'action' => 'getAIChatbotAIFavouredPages',
                ],
                'uniqueId' => 'widgetBotTrackinggetAIChatbotAIFavouredPages',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
        ],
    ],
    2 => [
        'uniqueId' => 'General_AIAssistants.BotTracking_AIChatbotsOverview',
        'category' => [
            'id' => 'General_AIAssistants',
            'name' => [
                'translationKey' => 'General_AIAssistants',
            ],
            'order' => '80',
            'icon' => 'icon-ai-assistants',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
                1 => 'CoreHome_AIInsights',
            ],
        ],
        'subcategory' => [
            'id' => 'BotTracking_AIChatbotsOverview',
            'name' => [
                'translationKey' => 'BotTracking_AIChatbotsOverview',
            ],
            'order' => '10',
            'help' => '<p>The AI Chatbots Overview page provides insights into website traffic originating from AI chatbots such as ChatGPT and other large language model–based assistants. These reports track key metrics including the number of requests made by these bots, the pages and documents they access, and any errors encountered. They also offer detailed breakdowns showing which bots visit specific page URLs, helping you understand how AI chatbots interact with your content and identify opportunities to improve visibility and accessibility for AI-driven users.</p><p>It’s important to note that none of these pages were actually viewed by humans in the traditional way — all requests originate from AI chatbots fetching content automatically.</p><p>Currently, these reports exclusively include requests from AI chatbots that do not execute JavaScript. They do not include traffic from AI crawlers used for training AI models or from AI agents capable of executing JavaScript.</p>',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'BotTracking_NoRecentRequestsWidgetTitle',
                ],
                'module' => 'BotTracking',
                'action' => 'noRecentRequestsMessage',
                'order' => '0',
                'parameters' => [
                    'module' => 'BotTracking',
                    'action' => 'noRecentRequestsMessage',
                ],
                'uniqueId' => 'widgetBotTrackingnoRecentRequestsMessage',
                'isWide' => '1',
                'middlewareParameters' => [
                    'module' => 'BotTracking',
                    'action' => 'showNoRecentRequestsMessage',
                ],
                'clientComponent' => [
                    'plugin' => 'BotTracking',
                    'name' => 'NoRecentRequestsWidget',
                ],
            ],
            1 => [
                'name' => [
                    'translationKey' => 'BotTracking_ReportTitleChatbotsOverTime',
                ],
                'module' => 'BotTracking',
                'action' => 'getEvolutionGraph',
                'order' => '1',
                'parameters' => [
                    'forceView' => '1',
                    'viewDataTable' => 'graphEvolution',
                    'module' => 'BotTracking',
                    'action' => 'getEvolutionGraph',
                ],
                'uniqueId' => 'widgetBotTrackinggetEvolutionGraphforceView1viewDataTablegraphEvolution',
                'isWide' => '0',
                'viewDataTable' => 'graphEvolution',
                'isReport' => '1',
            ],
            2 => [
                'name' => [
                    'translationKey' => 'BotTracking_AIChatbotsOverview',
                ],
                'module' => 'BotTracking',
                'action' => 'get',
                'order' => '2',
                'parameters' => [
                    'forceView' => '1',
                    'viewDataTable' => 'sparklines',
                    'module' => 'BotTracking',
                    'action' => 'get',
                ],
                'uniqueId' => 'widgetBotTrackinggetforceView1viewDataTablesparklines',
                'isWide' => '0',
                'viewDataTable' => 'sparklines',
                'isReport' => '1',
            ],
            3 => [
                'name' => [
                    'translationKey' => 'BotTracking_AIChatbotsReportTitle',
                ],
                'module' => 'BotTracking',
                'action' => 'getAIChatbotRequests',
                'order' => '130',
                'parameters' => [
                    'module' => 'BotTracking',
                    'action' => 'getAIChatbotRequests',
                ],
                'uniqueId' => 'widgetBotTrackinggetAIChatbotRequests',
                'isWide' => '1',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
        ],
    ],
    3 => [
        'uniqueId' => 'General_AIAssistants.BotTracking_AIChatbotsRealtime',
        'category' => [
            'id' => 'General_AIAssistants',
            'name' => [
                'translationKey' => 'General_AIAssistants',
            ],
            'order' => '80',
            'icon' => 'icon-ai-assistants',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
                1 => 'CoreHome_AIInsights',
            ],
        ],
        'subcategory' => [
            'id' => 'BotTracking_AIChatbotsRealtime',
            'name' => [
                'translationKey' => 'BotTracking_AIChatbotsRealtime',
            ],
            'order' => '12',
            'help' => '<p>The AI Chatbots Real-time page shows recent AI chatbot activity from raw bot tracking data, including chatbot request volume, unique page URLs, and HTTP error counts.</p><p>These reports are limited to short real-time windows so they remain fast and predictable on large sites.</p>',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'BotTracking_AIChatbotsLast30MinutesTitle',
                ],
                'module' => 'BotTracking',
                'action' => 'getAIChatbotsRealTime',
                'order' => '10',
                'parameters' => [
                    'module' => 'BotTracking',
                    'action' => 'getAIChatbotsRealTime',
                    'lastMinutes' => '30',
                ],
                'uniqueId' => 'widgetBotTrackinggetAIChatbotsRealTimelastMinutes30',
                'isWide' => '1',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
            1 => [
                'name' => [
                    'translationKey' => 'BotTracking_AIChatbotsLast8HoursTitle',
                ],
                'module' => 'BotTracking',
                'action' => 'getAIChatbotsRealTime',
                'order' => '20',
                'parameters' => [
                    'module' => 'BotTracking',
                    'action' => 'getAIChatbotsRealTime',
                    'lastMinutes' => '480',
                ],
                'uniqueId' => 'widgetBotTrackinggetAIChatbotsRealTimelastMinutes480',
                'isWide' => '1',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
            2 => [
                'name' => [
                    'translationKey' => 'BotTracking_TopPageUrlsLast30MinutesTitle',
                ],
                'module' => 'BotTracking',
                'action' => 'getTopPageUrlsRealTime',
                'order' => '30',
                'parameters' => [
                    'module' => 'BotTracking',
                    'action' => 'getTopPageUrlsRealTime',
                    'lastMinutes' => '30',
                ],
                'uniqueId' => 'widgetBotTrackinggetTopPageUrlsRealTimelastMinutes30',
                'isWide' => '1',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
            3 => [
                'name' => [
                    'translationKey' => 'BotTracking_TopPageUrlsLast8HoursTitle',
                ],
                'module' => 'BotTracking',
                'action' => 'getTopPageUrlsRealTime',
                'order' => '40',
                'parameters' => [
                    'module' => 'BotTracking',
                    'action' => 'getTopPageUrlsRealTime',
                    'lastMinutes' => '480',
                ],
                'uniqueId' => 'widgetBotTrackinggetTopPageUrlsRealTimelastMinutes480',
                'isWide' => '1',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
        ],
    ],
    4 => [
        'uniqueId' => 'General_Actions.VisitorInterest_Engagement',
        'category' => [
            'id' => 'General_Actions',
            'name' => [
                'translationKey' => 'Actions_Behaviour',
            ],
            'order' => '10',
            'icon' => 'icon-reporting-actions',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'VisitorInterest_Engagement',
            'name' => [
                'translationKey' => 'Tour_Engagement',
            ],
            'order' => '46',
            'help' => '<p>The Engagement section provides reports that help to quantify how many new and returning visitors you get. You can also review reports that break down the average time and number of pages per visit, as well as the number of times a visitor has been to your site and the most common number of days between visits.</p><p>This can help you to optimise for frequency and high-interaction visits in addition to maximising your reach.</p>',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'VisitorInterest_VisitsPerDuration',
                ],
                'module' => 'VisitorInterest',
                'action' => 'getNumberOfVisitsPerVisitDuration',
                'order' => '115',
                'parameters' => [
                    'module' => 'VisitorInterest',
                    'action' => 'getNumberOfVisitsPerVisitDuration',
                ],
                'uniqueId' => 'widgetVisitorInterestgetNumberOfVisitsPerVisitDuration',
                'isWide' => '0',
                'viewDataTable' => 'cloud',
                'isReport' => '1',
            ],
            1 => [
                'name' => [
                    'translationKey' => 'VisitorInterest_VisitsPerNbOfPages',
                ],
                'module' => 'VisitorInterest',
                'action' => 'getNumberOfVisitsPerPage',
                'order' => '120',
                'parameters' => [
                    'module' => 'VisitorInterest',
                    'action' => 'getNumberOfVisitsPerPage',
                ],
                'uniqueId' => 'widgetVisitorInterestgetNumberOfVisitsPerPage',
                'isWide' => '0',
                'viewDataTable' => 'cloud',
                'isReport' => '1',
            ],
            2 => [
                'name' => [
                    'translationKey' => 'VisitorInterest_visitsByVisitCount',
                ],
                'module' => 'VisitorInterest',
                'action' => 'getNumberOfVisitsByVisitCount',
                'order' => '125',
                'parameters' => [
                    'module' => 'VisitorInterest',
                    'action' => 'getNumberOfVisitsByVisitCount',
                ],
                'uniqueId' => 'widgetVisitorInterestgetNumberOfVisitsByVisitCount',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
            3 => [
                'name' => [
                    'translationKey' => 'VisitorInterest_VisitsByDaysSinceLast',
                ],
                'module' => 'VisitorInterest',
                'action' => 'getNumberOfVisitsByDaysSinceLast',
                'order' => '130',
                'parameters' => [
                    'module' => 'VisitorInterest',
                    'action' => 'getNumberOfVisitsByDaysSinceLast',
                ],
                'uniqueId' => 'widgetVisitorInterestgetNumberOfVisitsByDaysSinceLast',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
            4 => [
                'name' => [
                    'translationKey' => 'VisitFrequency_WidgetGraphReturning',
                ],
                'module' => 'VisitFrequency',
                'action' => 'getEvolutionGraph',
                'order' => '1',
                'parameters' => [
                    'forceView' => '1',
                    'viewDataTable' => 'graphEvolution',
                    'module' => 'VisitFrequency',
                    'action' => 'getEvolutionGraph',
                ],
                'uniqueId' => 'widgetVisitFrequencygetEvolutionGraphforceView1viewDataTablegraphEvolution',
                'isWide' => '0',
                'viewDataTable' => 'graphEvolution',
                'isReport' => '1',
            ],
            5 => [
                'name' => [
                    'translationKey' => 'VisitFrequency_WidgetOverview',
                ],
                'module' => 'VisitFrequency',
                'action' => 'get',
                'order' => '2',
                'parameters' => [
                    'forceView' => '1',
                    'viewDataTable' => 'sparklines',
                    'module' => 'VisitFrequency',
                    'action' => 'get',
                ],
                'uniqueId' => 'widgetVisitFrequencygetforceView1viewDataTablesparklines',
                'isWide' => '0',
                'viewDataTable' => 'sparklines',
                'isReport' => '1',
            ],
        ],
    ],
    5 => [
        'uniqueId' => 'General_Actions.Transitions_Transitions',
        'category' => [
            'id' => 'General_Actions',
            'name' => [
                'translationKey' => 'Actions_Behaviour',
            ],
            'order' => '10',
            'icon' => 'icon-reporting-actions',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'Transitions_Transitions',
            'name' => [
                'translationKey' => 'Transitions_Transitions',
            ],
            'order' => '46',
            'help' => '<p>Transitions is a report showing the things your visitors did directly before and after viewing a given page. This page explains how to access, understand, and use the powerful "Transitions" report.</p><p><a target="_blank" rel="noreferrer noopener" href="https://matomo.org/docs/transitions/?mtm_campaign=Matomo_App&mtm_source=Matomo_App_OnPremise&mtm_medium=App.Transitions.getTransitions">More details</a></p>',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'Transitions_Transitions',
                ],
                'module' => [
                    'translationKey' => 'Transitions_Transitions',
                ],
                'action' => 'getTransitions',
                'order' => '99',
                'parameters' => [
                    'module' => [
                        'translationKey' => 'Transitions_Transitions',
                    ],
                    'action' => 'getTransitions',
                ],
                'uniqueId' => 'widgetTransitionsgetTransitions',
                'isWide' => '0',
                'clientComponent' => [
                    'plugin' => [
                        'translationKey' => 'Transitions_Transitions',
                    ],
                    'name' => 'TransitionsPage',
                ],
            ],
        ],
    ],
    6 => [
        'uniqueId' => 'General_Actions.General_Downloads',
        'category' => [
            'id' => 'General_Actions',
            'name' => [
                'translationKey' => 'Actions_Behaviour',
            ],
            'order' => '10',
            'icon' => 'icon-reporting-actions',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'General_Downloads',
            'name' => [
                'translationKey' => 'General_Downloads',
            ],
            'order' => '35',
            'help' => '<p>In this report, you can see which files your visitors have downloaded.</p><p>What Matomo counts as a download is the click on a download link. Whether the download was completed or not isn\'t known to Matomo.</p>',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'General_Downloads',
                ],
                'module' => [
                    'translationKey' => 'General_Actions',
                ],
                'action' => 'getDownloads',
                'order' => '109',
                'parameters' => [
                    'module' => [
                        'translationKey' => 'General_Actions',
                    ],
                    'action' => 'getDownloads',
                ],
                'uniqueId' => 'widgetActionsgetDownloads',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
        ],
    ],
    7 => [
        'uniqueId' => 'General_Actions.Actions_SubmenuPagesEntry',
        'category' => [
            'id' => 'General_Actions',
            'name' => [
                'translationKey' => 'Actions_Behaviour',
            ],
            'order' => '10',
            'icon' => 'icon-reporting-actions',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'Actions_SubmenuPagesEntry',
            'name' => [
                'translationKey' => 'Actions_SubmenuPagesEntry',
            ],
            'order' => '10',
            'help' => '<p>This report contains information about the entry pages that were used during the specified period. An entry page is the first page that a user views during their visit.</p><p>The entry URLs are displayed as a folder structure.</p><p>Use the plus and minus icons on the left to navigate.</p>',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'Actions_SubmenuPagesEntry',
                ],
                'module' => [
                    'translationKey' => 'General_Actions',
                ],
                'action' => 'getEntryPageUrls',
                'order' => '103',
                'parameters' => [
                    'module' => [
                        'translationKey' => 'General_Actions',
                    ],
                    'action' => 'getEntryPageUrls',
                ],
                'uniqueId' => 'widgetActionsgetEntryPageUrls',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
            1 => [
                'name' => [
                    'translationKey' => 'Actions_WidgetEntryPageTitles',
                ],
                'module' => [
                    'translationKey' => 'General_Actions',
                ],
                'action' => 'getEntryPageTitles',
                'order' => '106',
                'parameters' => [
                    'module' => [
                        'translationKey' => 'General_Actions',
                    ],
                    'action' => 'getEntryPageTitles',
                ],
                'uniqueId' => 'widgetActionsgetEntryPageTitles',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
        ],
    ],
    8 => [
        'uniqueId' => 'General_Actions.Actions_SubmenuPagesExit',
        'category' => [
            'id' => 'General_Actions',
            'name' => [
                'translationKey' => 'Actions_Behaviour',
            ],
            'order' => '10',
            'icon' => 'icon-reporting-actions',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'Actions_SubmenuPagesExit',
            'name' => [
                'translationKey' => 'Actions_SubmenuPagesExit',
            ],
            'order' => '15',
            'help' => '<p>This report contains information about the exit pages that occurred during the specified period. An exit page is the last page that a user views during their visit.</p><p>The exit URLs are displayed as a folder structure.</p><p>Use the plus and minus icons on the left to navigate.</p>',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'Actions_SubmenuPagesExit',
                ],
                'module' => [
                    'translationKey' => 'General_Actions',
                ],
                'action' => 'getExitPageUrls',
                'order' => '104',
                'parameters' => [
                    'module' => [
                        'translationKey' => 'General_Actions',
                    ],
                    'action' => 'getExitPageUrls',
                ],
                'uniqueId' => 'widgetActionsgetExitPageUrls',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
            1 => [
                'name' => [
                    'translationKey' => 'Actions_ExitPageTitles',
                ],
                'module' => [
                    'translationKey' => 'General_Actions',
                ],
                'action' => 'getExitPageTitles',
                'order' => '107',
                'parameters' => [
                    'module' => [
                        'translationKey' => 'General_Actions',
                    ],
                    'action' => 'getExitPageTitles',
                ],
                'uniqueId' => 'widgetActionsgetExitPageTitles',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
        ],
    ],
    9 => [
        'uniqueId' => 'General_Actions.General_Outlinks',
        'category' => [
            'id' => 'General_Actions',
            'name' => [
                'translationKey' => 'Actions_Behaviour',
            ],
            'order' => '10',
            'icon' => 'icon-reporting-actions',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'General_Outlinks',
            'name' => [
                'translationKey' => 'General_Outlinks',
            ],
            'order' => '30',
            'help' => '<p>This report shows a hierarchical list of outlink URLs that were clicked by your visitors. An outlink is a link that leads the visitor away from your website (to another domain).</p><p>Use the plus and minus icons on the left to navigate.</p>',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'General_Outlinks',
                ],
                'module' => [
                    'translationKey' => 'General_Actions',
                ],
                'action' => 'getOutlinks',
                'order' => '108',
                'parameters' => [
                    'module' => [
                        'translationKey' => 'General_Actions',
                    ],
                    'action' => 'getOutlinks',
                ],
                'uniqueId' => 'widgetActionsgetOutlinks',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
        ],
    ],
    10 => [
        'uniqueId' => 'General_Actions.Actions_SubmenuPageTitles',
        'category' => [
            'id' => 'General_Actions',
            'name' => [
                'translationKey' => 'Actions_Behaviour',
            ],
            'order' => '10',
            'icon' => 'icon-reporting-actions',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'Actions_SubmenuPageTitles',
            'name' => [
                'translationKey' => 'Actions_SubmenuPageTitles',
            ],
            'order' => '20',
            'help' => '<p>This report contains information about the titles of the pages that have been visited.</p><p>The page title is the HTML &lt;title&gt; Tag that most browsers show in their window title.</p>',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'Actions_SubmenuPageTitles',
                ],
                'module' => [
                    'translationKey' => 'General_Actions',
                ],
                'action' => 'getPageTitles',
                'order' => '105',
                'parameters' => [
                    'module' => [
                        'translationKey' => 'General_Actions',
                    ],
                    'action' => 'getPageTitles',
                ],
                'uniqueId' => 'widgetActionsgetPageTitles',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
        ],
    ],
    11 => [
        'uniqueId' => 'General_Actions.General_Pages',
        'category' => [
            'id' => 'General_Actions',
            'name' => [
                'translationKey' => 'Actions_Behaviour',
            ],
            'order' => '10',
            'icon' => 'icon-reporting-actions',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'General_Pages',
            'name' => [
                'translationKey' => 'General_Pages',
            ],
            'order' => '5',
            'help' => '<p>This report contains information about the page URLs that have been visited.</p><p>The table is organized hierarchically, the URLs are displayed as a folder structure.</p><p>Use the plus and minus icons on the left to navigate.</p>',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'General_Pages',
                ],
                'module' => [
                    'translationKey' => 'General_Actions',
                ],
                'action' => 'getPageUrls',
                'order' => '102',
                'parameters' => [
                    'module' => [
                        'translationKey' => 'General_Actions',
                    ],
                    'action' => 'getPageUrls',
                ],
                'uniqueId' => 'widgetActionsgetPageUrls',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
        ],
    ],
    12 => [
        'uniqueId' => 'General_Actions.Actions_SubmenuSitesearch',
        'category' => [
            'id' => 'General_Actions',
            'name' => [
                'translationKey' => 'Actions_Behaviour',
            ],
            'order' => '10',
            'icon' => 'icon-reporting-actions',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'Actions_SubmenuSitesearch',
            'name' => [
                'translationKey' => 'Actions_SubmenuSitesearch',
            ],
            'order' => '25',
            'help' => '<p>The Site Search section shows which keywords visitors use when searching your website. It also displays which pages users view after performing a search and which on-site search keywords return no results at all.</p><p>These reports can give you ideas about missing content on your site, insight into what your visitors are looking for but can’t find easily, and more.</p><p><a target="_blank" rel="noreferrer noopener" href="https://matomo.org/docs/site-search/?mtm_campaign=Matomo_App&mtm_source=Matomo_App_OnPremise&mtm_medium=App.Actions.getSiteSearchCategories">Learn more in the Site Search guide.</a></p>',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'Actions_WidgetSearchKeywords',
                ],
                'module' => [
                    'translationKey' => 'General_Actions',
                ],
                'action' => 'getSiteSearchKeywords',
                'order' => '115',
                'parameters' => [
                    'module' => [
                        'translationKey' => 'General_Actions',
                    ],
                    'action' => 'getSiteSearchKeywords',
                ],
                'uniqueId' => 'widgetActionsgetSiteSearchKeywords',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
            1 => [
                'name' => [
                    'translationKey' => 'Actions_WidgetPageUrlsFollowingSearch',
                ],
                'module' => [
                    'translationKey' => 'General_Actions',
                ],
                'action' => 'getPageUrlsFollowingSiteSearch',
                'order' => '116',
                'parameters' => [
                    'module' => [
                        'translationKey' => 'General_Actions',
                    ],
                    'action' => 'getPageUrlsFollowingSiteSearch',
                ],
                'uniqueId' => 'widgetActionsgetPageUrlsFollowingSiteSearch',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
            2 => [
                'name' => [
                    'translationKey' => 'Actions_WidgetSearchNoResultKeywords',
                ],
                'module' => [
                    'translationKey' => 'General_Actions',
                ],
                'action' => 'getSiteSearchNoResultKeywords',
                'order' => '118',
                'parameters' => [
                    'module' => [
                        'translationKey' => 'General_Actions',
                    ],
                    'action' => 'getSiteSearchNoResultKeywords',
                ],
                'uniqueId' => 'widgetActionsgetSiteSearchNoResultKeywords',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
            3 => [
                'name' => [
                    'translationKey' => 'Actions_WidgetPageTitlesFollowingSearch',
                ],
                'module' => [
                    'translationKey' => 'General_Actions',
                ],
                'action' => 'getPageTitlesFollowingSiteSearch',
                'order' => '119',
                'parameters' => [
                    'module' => [
                        'translationKey' => 'General_Actions',
                    ],
                    'action' => 'getPageTitlesFollowingSiteSearch',
                ],
                'uniqueId' => 'widgetActionsgetPageTitlesFollowingSiteSearch',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
            4 => [
                'name' => [
                    'translationKey' => 'Actions_WidgetSearchCategories',
                ],
                'module' => [
                    'translationKey' => 'General_Actions',
                ],
                'action' => 'getSiteSearchCategories',
                'order' => '120',
                'parameters' => [
                    'module' => [
                        'translationKey' => 'General_Actions',
                    ],
                    'action' => 'getSiteSearchCategories',
                ],
                'uniqueId' => 'widgetActionsgetSiteSearchCategories',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
        ],
    ],
    13 => [
        'uniqueId' => 'General_Actions.Events_Events',
        'category' => [
            'id' => 'General_Actions',
            'name' => [
                'translationKey' => 'Actions_Behaviour',
            ],
            'order' => '10',
            'icon' => 'icon-reporting-actions',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'Events_Events',
            'name' => [
                'translationKey' => 'Events_Events',
            ],
            'order' => '40',
            'help' => '<p>The Events section offers reports on the custom events associated with your site. Events typically require custom configuration. Once configured you can review reports broken down by category, action and name.</p><p><a target="_blank" rel="noreferrer noopener" href="https://matomo.org/docs/event-tracking/?mtm_campaign=Matomo_App&mtm_source=Matomo_App_OnPremise&mtm_medium=App.Events.getCategory">Learn more about event tracking here.</a></p>',
        ],
        'widgets' => [
            0 => [
                'name' => '',
                'module' => 'CoreHome',
                'action' => 'renderWidgetContainer',
                'order' => '99',
                'parameters' => [
                    'module' => 'CoreHome',
                    'action' => 'renderWidgetContainer',
                    'containerId' => [
                        'translationKey' => 'Events_Events',
                    ],
                ],
                'uniqueId' => 'widgetEvents',
                'isWide' => '0',
                'layout' => 'ByDimension',
                'isContainer' => '1',
                'widgets' => [
                    0 => [
                        'name' => [
                            'translationKey' => 'Events_EventCategories',
                        ],
                        'category' => [
                            'id' => 'General_Actions',
                            'name' => [
                                'translationKey' => 'General_Actions',
                            ],
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Events_Events',
                            'name' => [
                                'translationKey' => 'Events_Events',
                            ],
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Events_Events',
                        ],
                        'action' => 'getCategory',
                        'order' => '100',
                        'parameters' => [
                            'module' => [
                                'translationKey' => 'Events_Events',
                            ],
                            'action' => 'getCategory',
                            'secondaryDimension' => 'eventAction',
                        ],
                        'uniqueId' => 'widgetEventsgetCategorysecondaryDimensioneventAction',
                        'isWide' => '0',
                        'viewDataTable' => 'table',
                        'isReport' => '1',
                    ],
                    1 => [
                        'name' => [
                            'translationKey' => 'Events_EventActions',
                        ],
                        'category' => [
                            'id' => 'General_Actions',
                            'name' => [
                                'translationKey' => 'General_Actions',
                            ],
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Events_Events',
                            'name' => [
                                'translationKey' => 'Events_Events',
                            ],
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Events_Events',
                        ],
                        'action' => 'getAction',
                        'order' => '101',
                        'parameters' => [
                            'module' => [
                                'translationKey' => 'Events_Events',
                            ],
                            'action' => 'getAction',
                            'secondaryDimension' => 'eventName',
                        ],
                        'uniqueId' => 'widgetEventsgetActionsecondaryDimensioneventName',
                        'isWide' => '0',
                        'viewDataTable' => 'table',
                        'isReport' => '1',
                    ],
                    2 => [
                        'name' => [
                            'translationKey' => 'Events_EventNames',
                        ],
                        'category' => [
                            'id' => 'General_Actions',
                            'name' => [
                                'translationKey' => 'General_Actions',
                            ],
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Events_Events',
                            'name' => [
                                'translationKey' => 'Events_Events',
                            ],
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Events_Events',
                        ],
                        'action' => 'getName',
                        'order' => '102',
                        'parameters' => [
                            'module' => [
                                'translationKey' => 'Events_Events',
                            ],
                            'action' => 'getName',
                            'secondaryDimension' => 'eventAction',
                        ],
                        'uniqueId' => 'widgetEventsgetNamesecondaryDimensioneventAction',
                        'isWide' => '0',
                        'viewDataTable' => 'table',
                        'isReport' => '1',
                    ],
                ],
            ],
        ],
    ],
    14 => [
        'uniqueId' => 'General_Actions.Contents_Contents',
        'category' => [
            'id' => 'General_Actions',
            'name' => [
                'translationKey' => 'Actions_Behaviour',
            ],
            'order' => '10',
            'icon' => 'icon-reporting-actions',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'Contents_Contents',
            'name' => [
                'translationKey' => 'Contents_Contents',
            ],
            'order' => '45',
            'help' => '<p>Content tracking helps you determine the popularity of specific pieces of content on any page of your website or app. This section reports the number of impressions and interactions the various pieces of content on your site receive.</p><p><a target="_blank" rel="noreferrer noopener" href="https://matomo.org/docs/content-tracking?mtm_campaign=Matomo_App&mtm_source=Matomo_App_OnPremise&mtm_medium=App.Contents.getContentNames">Learn more in the Content Tracking guide.</a></p>',
        ],
        'widgets' => [
            0 => [
                'name' => '',
                'module' => 'CoreHome',
                'action' => 'renderWidgetContainer',
                'order' => '99',
                'parameters' => [
                    'module' => 'CoreHome',
                    'action' => 'renderWidgetContainer',
                    'containerId' => [
                        'translationKey' => 'Contents_Contents',
                    ],
                ],
                'uniqueId' => 'widgetContents',
                'isWide' => '0',
                'layout' => 'ByDimension',
                'isContainer' => '1',
                'widgets' => [
                    0 => [
                        'name' => [
                            'translationKey' => 'Contents_ContentName',
                        ],
                        'category' => [
                            'id' => 'General_Actions',
                            'name' => [
                                'translationKey' => 'General_Actions',
                            ],
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Contents_Contents',
                            'name' => [
                                'translationKey' => 'Contents_Contents',
                            ],
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Contents_Contents',
                        ],
                        'action' => 'getContentNames',
                        'order' => '135',
                        'parameters' => [
                            'module' => [
                                'translationKey' => 'Contents_Contents',
                            ],
                            'action' => 'getContentNames',
                        ],
                        'uniqueId' => 'widgetContentsgetContentNames',
                        'isWide' => '0',
                        'viewDataTable' => 'table',
                        'isReport' => '1',
                    ],
                    1 => [
                        'name' => [
                            'translationKey' => 'Contents_ContentPiece',
                        ],
                        'category' => [
                            'id' => 'General_Actions',
                            'name' => [
                                'translationKey' => 'General_Actions',
                            ],
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Contents_Contents',
                            'name' => [
                                'translationKey' => 'Contents_Contents',
                            ],
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Contents_Contents',
                        ],
                        'action' => 'getContentPieces',
                        'order' => '136',
                        'parameters' => [
                            'module' => [
                                'translationKey' => 'Contents_Contents',
                            ],
                            'action' => 'getContentPieces',
                        ],
                        'uniqueId' => 'widgetContentsgetContentPieces',
                        'isWide' => '0',
                        'viewDataTable' => 'table',
                        'isReport' => '1',
                    ],
                ],
            ],
        ],
    ],
    15 => [
        'uniqueId' => 'General_Actions.PagePerformance_Performance',
        'category' => [
            'id' => 'General_Actions',
            'name' => [
                'translationKey' => 'Actions_Behaviour',
            ],
            'order' => '10',
            'icon' => 'icon-reporting-actions',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'PagePerformance_Performance',
            'name' => [
                'translationKey' => 'PagePerformance_Performance',
            ],
            'order' => '47',
            'help' => '<p>The Performance section can help you analyse how fast your website or app is performing on the whole and help discover whether you have specific pages that significantly deviate from your averages.</p><p>You can also find reports showing exactly how long each page of your website takes to load and what is contributing to their loading time.</p>',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'PagePerformance_EvolutionOverPeriod',
                ],
                'module' => 'PagePerformance',
                'action' => 'getEvolutionGraph',
                'order' => '1',
                'parameters' => [
                    'forceView' => '1',
                    'viewDataTable' => 'graphStackedBarEvolution',
                    'module' => 'PagePerformance',
                    'action' => 'getEvolutionGraph',
                ],
                'uniqueId' => 'widgetPagePerformancegetEvolutionGraphforceView1viewDataTablegraphStackedBarEvolution',
                'isWide' => '0',
                'viewDataTable' => 'graphStackedBarEvolution',
                'isReport' => '1',
            ],
            1 => [
                'name' => '',
                'module' => 'PagePerformance',
                'action' => 'get',
                'order' => '2',
                'parameters' => [
                    'forceView' => '1',
                    'viewDataTable' => 'sparklines',
                    'module' => 'PagePerformance',
                    'action' => 'get',
                ],
                'uniqueId' => 'widgetPagePerformancegetforceView1viewDataTablesparklines',
                'isWide' => '0',
                'viewDataTable' => 'sparklines',
                'isReport' => '1',
            ],
            2 => [
                'name' => [
                    'translationKey' => 'Actions_PageUrls',
                ],
                'module' => [
                    'translationKey' => 'General_Actions',
                ],
                'action' => 'getPageUrls',
                'order' => '3',
                'parameters' => [
                    'forceView' => '1',
                    'viewDataTable' => 'tablePerformanceColumns',
                    'module' => [
                        'translationKey' => 'General_Actions',
                    ],
                    'action' => 'getPageUrls',
                    'performance' => '1',
                ],
                'uniqueId' => 'widgetActionsgetPageUrlsforceView1viewDataTabletablePerformanceColumnsperformance1',
                'isWide' => '1',
                'viewDataTable' => 'tablePerformanceColumns',
                'isReport' => '1',
            ],
            3 => [
                'name' => [
                    'translationKey' => 'Actions_SubmenuPageTitles',
                ],
                'module' => [
                    'translationKey' => 'General_Actions',
                ],
                'action' => 'getPageTitles',
                'order' => '4',
                'parameters' => [
                    'forceView' => '1',
                    'viewDataTable' => 'tablePerformanceColumns',
                    'module' => [
                        'translationKey' => 'General_Actions',
                    ],
                    'action' => 'getPageTitles',
                    'performance' => '1',
                ],
                'uniqueId' => 'widgetActionsgetPageTitlesforceView1viewDataTabletablePerformanceColumnsperformance1',
                'isWide' => '1',
                'viewDataTable' => 'tablePerformanceColumns',
                'isReport' => '1',
            ],
        ],
    ],
    16 => [
        'uniqueId' => 'General_Visitors.DevicesDetection_Devices',
        'category' => [
            'id' => 'General_Visitors',
            'name' => [
                'translationKey' => 'General_Visitors',
            ],
            'order' => '5',
            'icon' => 'icon-reporting-visitors',
            'help' => '<p>The Visitors pages tell you things about who your visitors are. Things like where your visitors came from, what devices and browsers they\'re using and when they generally visit your website. Understand, in the aggregate, who your audience is, and look for outliers to see how your audience could grow.</p><p>In addition to general information about your visitors, you can also use the <a href="#" onclick="this.href=broadcast.buildReportingUrl(\'category=General_Visitors&subcategory=Live_VisitorLog\')">Visits Log</a> to see what occurred in every individual visit.</p>',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'DevicesDetection_Devices',
            'name' => [
                'translationKey' => 'DevicesDetection_Devices',
            ],
            'order' => '15',
            'help' => '<p>The Devices section helps you understand the technology that your visitors are using to access your site. You will see reports on the type of device and specific models to enable you to optimise your site for the most popular devices.</p>',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'DevicesDetection_DeviceType',
                ],
                'module' => 'DevicesDetection',
                'action' => 'getType',
                'order' => '100',
                'parameters' => [
                    'module' => 'DevicesDetection',
                    'action' => 'getType',
                ],
                'uniqueId' => 'widgetDevicesDetectiongetType',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
            1 => [
                'name' => [
                    'translationKey' => 'DevicesDetection_DeviceModel',
                ],
                'module' => 'DevicesDetection',
                'action' => 'getModel',
                'order' => '102',
                'parameters' => [
                    'module' => 'DevicesDetection',
                    'action' => 'getModel',
                ],
                'uniqueId' => 'widgetDevicesDetectiongetModel',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
            2 => [
                'name' => [
                    'translationKey' => 'DevicesDetection_DeviceBrand',
                ],
                'module' => 'DevicesDetection',
                'action' => 'getBrand',
                'order' => '104',
                'parameters' => [
                    'module' => 'DevicesDetection',
                    'action' => 'getBrand',
                ],
                'uniqueId' => 'widgetDevicesDetectiongetBrand',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
            3 => [
                'name' => [
                    'translationKey' => 'Resolution_WidgetResolutions',
                ],
                'module' => [
                    'translationKey' => 'Resolution_ColumnResolution',
                ],
                'action' => 'getResolution',
                'order' => '108',
                'parameters' => [
                    'module' => [
                        'translationKey' => 'Resolution_ColumnResolution',
                    ],
                    'action' => 'getResolution',
                ],
                'uniqueId' => 'widgetResolutiongetResolution',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
        ],
    ],
    17 => [
        'uniqueId' => 'General_Visitors.DevicesDetection_Software',
        'category' => [
            'id' => 'General_Visitors',
            'name' => [
                'translationKey' => 'General_Visitors',
            ],
            'order' => '5',
            'icon' => 'icon-reporting-visitors',
            'help' => '<p>The Visitors pages tell you things about who your visitors are. Things like where your visitors came from, what devices and browsers they\'re using and when they generally visit your website. Understand, in the aggregate, who your audience is, and look for outliers to see how your audience could grow.</p><p>In addition to general information about your visitors, you can also use the <a href="#" onclick="this.href=broadcast.buildReportingUrl(\'category=General_Visitors&subcategory=Live_VisitorLog\')">Visits Log</a> to see what occurred in every individual visit.</p>',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'DevicesDetection_Software',
            'name' => [
                'translationKey' => 'DevicesDetection_Software',
            ],
            'order' => '20',
            'help' => '<p>The Software section shows the operating systems, browsers and plugins that your visitors are using to access the site so that you can optimise your site to ensure it is fully compatible with the most popular configurations.</p>',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'DevicesDetection_OperatingSystemVersions',
                ],
                'module' => 'DevicesDetection',
                'action' => 'getOsVersions',
                'order' => '102',
                'parameters' => [
                    'module' => 'DevicesDetection',
                    'action' => 'getOsVersions',
                ],
                'uniqueId' => 'widgetDevicesDetectiongetOsVersions',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
            1 => [
                'name' => [
                    'translationKey' => 'DevicesDetection_Browsers',
                ],
                'module' => 'DevicesDetection',
                'action' => 'getBrowsers',
                'order' => '105',
                'parameters' => [
                    'module' => 'DevicesDetection',
                    'action' => 'getBrowsers',
                ],
                'uniqueId' => 'widgetDevicesDetectiongetBrowsers',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
            2 => [
                'name' => [
                    'translationKey' => 'DevicesDetection_BrowserVersion',
                ],
                'module' => 'DevicesDetection',
                'action' => 'getBrowserVersions',
                'order' => '106',
                'parameters' => [
                    'module' => 'DevicesDetection',
                    'action' => 'getBrowserVersions',
                ],
                'uniqueId' => 'widgetDevicesDetectiongetBrowserVersions',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
            3 => [
                'name' => [
                    'translationKey' => 'Resolution_Configurations',
                ],
                'module' => [
                    'translationKey' => 'Resolution_ColumnResolution',
                ],
                'action' => 'getConfiguration',
                'order' => '107',
                'parameters' => [
                    'module' => [
                        'translationKey' => 'Resolution_ColumnResolution',
                    ],
                    'action' => 'getConfiguration',
                ],
                'uniqueId' => 'widgetResolutiongetConfiguration',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
            4 => [
                'name' => [
                    'translationKey' => 'DevicesDetection_OperatingSystemFamilies',
                ],
                'module' => 'DevicesDetection',
                'action' => 'getOsFamilies',
                'order' => '108',
                'parameters' => [
                    'module' => 'DevicesDetection',
                    'action' => 'getOsFamilies',
                ],
                'uniqueId' => 'widgetDevicesDetectiongetOsFamilies',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
            5 => [
                'name' => [
                    'translationKey' => 'DevicesDetection_BrowserEngines',
                ],
                'module' => 'DevicesDetection',
                'action' => 'getBrowserEngines',
                'order' => '110',
                'parameters' => [
                    'module' => 'DevicesDetection',
                    'action' => 'getBrowserEngines',
                ],
                'uniqueId' => 'widgetDevicesDetectiongetBrowserEngines',
                'isWide' => '0',
                'viewDataTable' => 'graphPie',
                'isReport' => '1',
            ],
            6 => [
                'name' => [
                    'translationKey' => 'DevicePlugins_WidgetPlugins',
                ],
                'module' => 'DevicePlugins',
                'action' => 'getPlugin',
                'order' => '113',
                'parameters' => [
                    'module' => 'DevicePlugins',
                    'action' => 'getPlugin',
                ],
                'uniqueId' => 'widgetDevicePluginsgetPlugin',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
        ],
    ],
    18 => [
        'uniqueId' => 'General_Visitors.General_Overview',
        'category' => [
            'id' => 'General_Visitors',
            'name' => [
                'translationKey' => 'General_Visitors',
            ],
            'order' => '5',
            'icon' => 'icon-reporting-visitors',
            'help' => '<p>The Visitors pages tell you things about who your visitors are. Things like where your visitors came from, what devices and browsers they\'re using and when they generally visit your website. Understand, in the aggregate, who your audience is, and look for outliers to see how your audience could grow.</p><p>In addition to general information about your visitors, you can also use the <a href="#" onclick="this.href=broadcast.buildReportingUrl(\'category=General_Visitors&subcategory=Live_VisitorLog\')">Visits Log</a> to see what occurred in every individual visit.</p>',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'General_Overview',
            'name' => [
                'translationKey' => 'General_Overview',
            ],
            'order' => '2',
            'help' => '<p>The Visitors Overview helps you understand the popularity of your site. It does this by providing charts that show how many visits your site is receiving over a selected period and the average level of engagement for key features, such as searches and downloads.</p>',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'VisitsSummary_WidgetLastVisits',
                ],
                'module' => 'VisitsSummary',
                'action' => 'getEvolutionGraph',
                'order' => '5',
                'parameters' => [
                    'forceView' => '1',
                    'viewDataTable' => 'graphEvolution',
                    'module' => 'VisitsSummary',
                    'action' => 'getEvolutionGraph',
                ],
                'uniqueId' => 'widgetVisitsSummarygetEvolutionGraphforceView1viewDataTablegraphEvolution',
                'isWide' => '0',
                'viewDataTable' => 'graphEvolution',
                'isReport' => '1',
            ],
            1 => [
                'name' => [
                    'translationKey' => 'VisitsSummary_WidgetVisits',
                ],
                'module' => 'VisitsSummary',
                'action' => 'get',
                'order' => '10',
                'parameters' => [
                    'forceView' => '1',
                    'viewDataTable' => 'sparklines',
                    'module' => 'VisitsSummary',
                    'action' => 'get',
                ],
                'uniqueId' => 'widgetVisitsSummarygetforceView1viewDataTablesparklines',
                'isWide' => '0',
                'viewDataTable' => 'sparklines',
                'isReport' => '1',
            ],
        ],
    ],
    19 => [
        'uniqueId' => 'General_Visitors.UserCountry_SubmenuLocations',
        'category' => [
            'id' => 'General_Visitors',
            'name' => [
                'translationKey' => 'General_Visitors',
            ],
            'order' => '5',
            'icon' => 'icon-reporting-visitors',
            'help' => '<p>The Visitors pages tell you things about who your visitors are. Things like where your visitors came from, what devices and browsers they\'re using and when they generally visit your website. Understand, in the aggregate, who your audience is, and look for outliers to see how your audience could grow.</p><p>In addition to general information about your visitors, you can also use the <a href="#" onclick="this.href=broadcast.buildReportingUrl(\'category=General_Visitors&subcategory=Live_VisitorLog\')">Visits Log</a> to see what occurred in every individual visit.</p>',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'UserCountry_SubmenuLocations',
            'name' => [
                'translationKey' => 'UserCountry_SubmenuLocations',
            ],
            'order' => '10',
            'help' => '<p>The "Locations" section is the best way to find out what countries, continents, regions, and cities your website visitors come from — in table and map form. It also says what language their browser is set to, helping identify international visitors in alternative locations.</p>',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'UserCountryMap_VisitorMap',
                ],
                'module' => 'UserCountryMap',
                'action' => 'visitorMap',
                'order' => '1',
                'parameters' => [
                    'module' => 'UserCountryMap',
                    'action' => 'visitorMap',
                ],
                'uniqueId' => 'widgetUserCountryMapvisitorMap',
                'isWide' => '0',
            ],
            1 => [
                'name' => [
                    'translationKey' => 'UserCountry_Country',
                ],
                'module' => 'UserCountry',
                'action' => 'getCountry',
                'order' => '105',
                'parameters' => [
                    'module' => 'UserCountry',
                    'action' => 'getCountry',
                ],
                'uniqueId' => 'widgetUserCountrygetCountry',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
            2 => [
                'name' => '',
                'module' => 'CoreHome',
                'action' => 'renderWidgetContainer',
                'order' => '106',
                'parameters' => [
                    'module' => 'CoreHome',
                    'action' => 'renderWidgetContainer',
                    'containerId' => [
                        'translationKey' => 'UserCountry_Continent',
                    ],
                ],
                'uniqueId' => 'widgetContinent',
                'isWide' => '0',
                'layout' => '',
                'isContainer' => '1',
                'widgets' => [
                    0 => [
                        'name' => [
                            'translationKey' => 'UserCountry_Continent',
                        ],
                        'category' => [
                            'id' => 'General_Visitors',
                            'name' => [
                                'translationKey' => 'General_Visitors',
                            ],
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'UserCountry_SubmenuLocations',
                            'name' => [
                                'translationKey' => 'UserCountry_SubmenuLocations',
                            ],
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'UserCountry',
                        'action' => 'getContinent',
                        'order' => '106',
                        'parameters' => [
                            'module' => 'UserCountry',
                            'action' => 'getContinent',
                        ],
                        'uniqueId' => 'widgetUserCountrygetContinent',
                        'isWide' => '0',
                        'viewDataTable' => 'table',
                        'isReport' => '1',
                    ],
                    1 => [
                        'name' => '',
                        'category' => [
                            'id' => 'General_Visitors',
                            'name' => [
                                'translationKey' => 'General_Visitors',
                            ],
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'UserCountry_SubmenuLocations',
                            'name' => [
                                'translationKey' => 'UserCountry_SubmenuLocations',
                            ],
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'UserCountry',
                        'action' => 'getDistinctCountries',
                        'order' => '106',
                        'parameters' => [
                            'module' => 'UserCountry',
                            'action' => 'getDistinctCountries',
                        ],
                        'uniqueId' => 'widgetUserCountrygetDistinctCountries',
                        'isWide' => '0',
                        'viewDataTable' => 'table',
                        'isReport' => '1',
                    ],
                ],
            ],
            3 => [
                'name' => [
                    'translationKey' => 'UserCountry_Region',
                ],
                'module' => 'UserCountry',
                'action' => 'getRegion',
                'order' => '107',
                'parameters' => [
                    'module' => 'UserCountry',
                    'action' => 'getRegion',
                ],
                'uniqueId' => 'widgetUserCountrygetRegion',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
            4 => [
                'name' => [
                    'translationKey' => 'UserLanguage_BrowserLanguage',
                ],
                'module' => 'UserLanguage',
                'action' => 'getLanguage',
                'order' => '108',
                'parameters' => [
                    'module' => 'UserLanguage',
                    'action' => 'getLanguage',
                ],
                'uniqueId' => 'widgetUserLanguagegetLanguage',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
            5 => [
                'name' => [
                    'translationKey' => 'UserCountry_City',
                ],
                'module' => 'UserCountry',
                'action' => 'getCity',
                'order' => '110',
                'parameters' => [
                    'module' => 'UserCountry',
                    'action' => 'getCity',
                ],
                'uniqueId' => 'widgetUserCountrygetCity',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
            6 => [
                'name' => [
                    'translationKey' => 'UserLanguage_LanguageCode',
                ],
                'module' => 'UserLanguage',
                'action' => 'getLanguageCode',
                'order' => '111',
                'parameters' => [
                    'module' => 'UserLanguage',
                    'action' => 'getLanguageCode',
                ],
                'uniqueId' => 'widgetUserLanguagegetLanguageCode',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
        ],
    ],
    20 => [
        'uniqueId' => 'General_Visitors.VisitTime_SubmenuTimes',
        'category' => [
            'id' => 'General_Visitors',
            'name' => [
                'translationKey' => 'General_Visitors',
            ],
            'order' => '5',
            'icon' => 'icon-reporting-visitors',
            'help' => '<p>The Visitors pages tell you things about who your visitors are. Things like where your visitors came from, what devices and browsers they\'re using and when they generally visit your website. Understand, in the aggregate, who your audience is, and look for outliers to see how your audience could grow.</p><p>In addition to general information about your visitors, you can also use the <a href="#" onclick="this.href=broadcast.buildReportingUrl(\'category=General_Visitors&subcategory=Live_VisitorLog\')">Visits Log</a> to see what occurred in every individual visit.</p>',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'VisitTime_SubmenuTimes',
            'name' => [
                'translationKey' => 'VisitTime_SubmenuTimes',
            ],
            'order' => '35',
            'help' => '<p>The "Times" section shows when people visit your site. Popular local times helps you cater your site to their lives. The most popular server times reveals technical demand.</p>',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'VisitTime_LocalTime',
                ],
                'module' => 'VisitTime',
                'action' => 'getVisitInformationPerLocalTime',
                'order' => '115',
                'parameters' => [
                    'module' => 'VisitTime',
                    'action' => 'getVisitInformationPerLocalTime',
                ],
                'uniqueId' => 'widgetVisitTimegetVisitInformationPerLocalTime',
                'isWide' => '0',
                'viewDataTable' => 'graphVerticalBar',
                'isReport' => '1',
            ],
            1 => [
                'name' => [
                    'translationKey' => 'VisitTime_SiteTime',
                ],
                'module' => 'VisitTime',
                'action' => 'getVisitInformationPerServerTime',
                'order' => '120',
                'parameters' => [
                    'module' => 'VisitTime',
                    'action' => 'getVisitInformationPerServerTime',
                ],
                'uniqueId' => 'widgetVisitTimegetVisitInformationPerServerTime',
                'isWide' => '0',
                'viewDataTable' => 'graphVerticalBar',
                'isReport' => '1',
            ],
            2 => [
                'name' => [
                    'translationKey' => 'VisitTime_VisitsByDayOfWeek',
                ],
                'module' => 'VisitTime',
                'action' => 'getByDayOfWeek',
                'order' => '125',
                'parameters' => [
                    'module' => 'VisitTime',
                    'action' => 'getByDayOfWeek',
                ],
                'uniqueId' => 'widgetVisitTimegetByDayOfWeek',
                'isWide' => '0',
                'viewDataTable' => 'graphVerticalBar',
                'isReport' => '1',
            ],
        ],
    ],
    21 => [
        'uniqueId' => 'General_Visitors.UserCountryMap_RealTimeMap',
        'category' => [
            'id' => 'General_Visitors',
            'name' => [
                'translationKey' => 'General_Visitors',
            ],
            'order' => '5',
            'icon' => 'icon-reporting-visitors',
            'help' => '<p>The Visitors pages tell you things about who your visitors are. Things like where your visitors came from, what devices and browsers they\'re using and when they generally visit your website. Understand, in the aggregate, who your audience is, and look for outliers to see how your audience could grow.</p><p>In addition to general information about your visitors, you can also use the <a href="#" onclick="this.href=broadcast.buildReportingUrl(\'category=General_Visitors&subcategory=Live_VisitorLog\')">Visits Log</a> to see what occurred in every individual visit.</p>',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'UserCountryMap_RealTimeMap',
            'name' => [
                'translationKey' => 'UserCountryMap_RealTimeMap',
            ],
            'order' => '9',
            'help' => '<p>Shows the location of website visitors the last 30 minutes, and flashes for new ones. Recent visits are shown as large orange bubbles, and older ones as smaller gray ones. It refreshes every five seconds.</p>',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'UserCountryMap_RealTimeMap',
                ],
                'module' => 'UserCountryMap',
                'action' => 'realtimeMap',
                'order' => '15',
                'parameters' => [
                    'module' => 'UserCountryMap',
                    'action' => 'realtimeMap',
                ],
                'uniqueId' => 'widgetUserCountryMaprealtimeMap',
                'isWide' => '1',
            ],
        ],
    ],
    22 => [
        'uniqueId' => 'General_Visitors.General_RealTime',
        'category' => [
            'id' => 'General_Visitors',
            'name' => [
                'translationKey' => 'General_Visitors',
            ],
            'order' => '5',
            'icon' => 'icon-reporting-visitors',
            'help' => '<p>The Visitors pages tell you things about who your visitors are. Things like where your visitors came from, what devices and browsers they\'re using and when they generally visit your website. Understand, in the aggregate, who your audience is, and look for outliers to see how your audience could grow.</p><p>In addition to general information about your visitors, you can also use the <a href="#" onclick="this.href=broadcast.buildReportingUrl(\'category=General_Visitors&subcategory=Live_VisitorLog\')">Visits Log</a> to see what occurred in every individual visit.</p>',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'General_RealTime',
            'name' => [
                'translationKey' => 'General_RealTime',
            ],
            'order' => '7',
            'help' => '<p>The visits in the real-time report show the real-time flow of visits to your website. It includes a real-time counter of your visits and page views in the last 24 hours and the previous 30 minutes.</p><p>This report refreshes every 5 seconds and displays new visits (or existing visitors that view a new page) at the top of the list with a fade-in effect.</p>',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'Live_VisitorsInRealTime',
                ],
                'module' => [
                    'translationKey' => 'General_Live',
                ],
                'action' => 'widget',
                'order' => '20',
                'parameters' => [
                    'module' => [
                        'translationKey' => 'General_Live',
                    ],
                    'action' => 'widget',
                ],
                'uniqueId' => 'widgetLivewidget',
                'isWide' => '1',
            ],
        ],
    ],
    23 => [
        'uniqueId' => 'General_Visitors.Live_VisitorLog',
        'category' => [
            'id' => 'General_Visitors',
            'name' => [
                'translationKey' => 'General_Visitors',
            ],
            'order' => '5',
            'icon' => 'icon-reporting-visitors',
            'help' => '<p>The Visitors pages tell you things about who your visitors are. Things like where your visitors came from, what devices and browsers they\'re using and when they generally visit your website. Understand, in the aggregate, who your audience is, and look for outliers to see how your audience could grow.</p><p>In addition to general information about your visitors, you can also use the <a href="#" onclick="this.href=broadcast.buildReportingUrl(\'category=General_Visitors&subcategory=Live_VisitorLog\')">Visits Log</a> to see what occurred in every individual visit.</p>',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'Live_VisitorLog',
            'name' => [
                'translationKey' => 'Live_VisitsLog',
            ],
            'order' => '5',
            'help' => '<p>The visits log shows you every visit your website receives in detail. Find out which actions each visitor has performed, how they got to your site, a bit about who they are, and more (while still complying with your local privacy regulations).</p><p>While other reports in Matomo show how your visitors behave at an aggregate level, the visits log provides granular detail. You can also use segments to narrow it down to specific types of visits to understand your visitors better.</p><p><a target="_blank" rel="noreferrer noopener" href="https://matomo.org/docs/real-time/?mtm_campaign=Matomo_App&mtm_source=Matomo_App_OnPremise&mtm_medium=App.Live.getLastVisitsDetails">Learn more in the visit-log guide.</a></p>',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'Live_VisitsLog',
                ],
                'module' => [
                    'translationKey' => 'General_Live',
                ],
                'action' => 'getLastVisitsDetails',
                'order' => '10',
                'parameters' => [
                    'forceView' => '1',
                    'viewDataTable' => 'VisitorLog',
                    'module' => [
                        'translationKey' => 'General_Live',
                    ],
                    'action' => 'getLastVisitsDetails',
                    'small' => '1',
                ],
                'uniqueId' => 'widgetLivegetLastVisitsDetailsforceView1viewDataTableVisitorLogsmall1',
                'isWide' => '0',
                'viewDataTable' => 'VisitorLog',
                'isReport' => '1',
            ],
        ],
    ],
    24 => [
        'uniqueId' => 'General_Visitors.UserId_UserReportTitle',
        'category' => [
            'id' => 'General_Visitors',
            'name' => [
                'translationKey' => 'General_Visitors',
            ],
            'order' => '5',
            'icon' => 'icon-reporting-visitors',
            'help' => '<p>The Visitors pages tell you things about who your visitors are. Things like where your visitors came from, what devices and browsers they\'re using and when they generally visit your website. Understand, in the aggregate, who your audience is, and look for outliers to see how your audience could grow.</p><p>In addition to general information about your visitors, you can also use the <a href="#" onclick="this.href=broadcast.buildReportingUrl(\'category=General_Visitors&subcategory=Live_VisitorLog\')">Visits Log</a> to see what occurred in every individual visit.</p>',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'UserId_UserReportTitle',
            'name' => [
                'translationKey' => 'General_UserIds',
            ],
            'order' => '40',
            'help' => '<p>The user ID report shows visits associated with all your registered and logged in users. Understand website usage by its specific users and find out who your most and least active users are.</p><p><a target="_blank" rel="noreferrer noopener" href="https://matomo.org/docs/user-id?mtm_campaign=Matomo_App&mtm_source=Matomo_App_OnPremise&mtm_medium=App.UserId.getUsers"><span class="icon-info"></span> Learn more</a></p>',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'General_UserIds',
                ],
                'module' => [
                    'translationKey' => 'UserId_UserId',
                ],
                'action' => 'getUsers',
                'order' => '109',
                'parameters' => [
                    'module' => [
                        'translationKey' => 'UserId_UserId',
                    ],
                    'action' => 'getUsers',
                ],
                'uniqueId' => 'widgetUserIdgetUsers',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
        ],
    ],
    25 => [
        'uniqueId' => 'General_Visitors.CustomVariables_CustomVariables',
        'category' => [
            'id' => 'General_Visitors',
            'name' => [
                'translationKey' => 'General_Visitors',
            ],
            'order' => '5',
            'icon' => 'icon-reporting-visitors',
            'help' => '<p>The Visitors pages tell you things about who your visitors are. Things like where your visitors came from, what devices and browsers they\'re using and when they generally visit your website. Understand, in the aggregate, who your audience is, and look for outliers to see how your audience could grow.</p><p>In addition to general information about your visitors, you can also use the <a href="#" onclick="this.href=broadcast.buildReportingUrl(\'category=General_Visitors&subcategory=Live_VisitorLog\')">Visits Log</a> to see what occurred in every individual visit.</p>',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'CustomVariables_CustomVariables',
            'name' => 'Custom Variables',
            'order' => '45',
            'help' => '<p>This report contains information about your Custom Variables. Click on a variable name to see the distribution of the values.</p><p><a href="https://matomo.org/docs/custom-variables/" rel="noreferrer noopener" target="_blank">Read more about this topic in the online guide.</a></p>',
        ],
        'widgets' => [
            0 => [
                'name' => 'Custom Variables',
                'module' => 'CustomVariables',
                'action' => 'getCustomVariables',
                'order' => '110',
                'parameters' => [
                    'module' => 'CustomVariables',
                    'action' => 'getCustomVariables',
                ],
                'uniqueId' => 'widgetCustomVariablesgetCustomVariables',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
        ],
    ],
    26 => [
        'uniqueId' => 'General_Visitors.CoreHome_Segments',
        'category' => [
            'id' => 'General_Visitors',
            'name' => [
                'translationKey' => 'General_Visitors',
            ],
            'order' => '5',
            'icon' => 'icon-reporting-visitors',
            'help' => '<p>The Visitors pages tell you things about who your visitors are. Things like where your visitors came from, what devices and browsers they\'re using and when they generally visit your website. Understand, in the aggregate, who your audience is, and look for outliers to see how your audience could grow.</p><p>In addition to general information about your visitors, you can also use the <a href="#" onclick="this.href=broadcast.buildReportingUrl(\'category=General_Visitors&subcategory=Live_VisitorLog\')">Visits Log</a> to see what occurred in every individual visit.</p>',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'CoreHome_Segments',
            'name' => [
                'translationKey' => 'CoreHome_Segments',
            ],
            'order' => '99',
            'help' => '',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'CoreHome_Segments',
                ],
                'module' => 'SegmentEditor',
                'action' => 'manageSegments',
                'order' => '99',
                'parameters' => [
                    'module' => 'SegmentEditor',
                    'action' => 'manageSegments',
                ],
                'uniqueId' => 'widgetSegmentEditormanageSegments',
                'isWide' => '0',
            ],
        ],
    ],
    27 => [
        'uniqueId' => 'Dashboard_Dashboard.1',
        'category' => [
            'id' => 'Dashboard_Dashboard',
            'name' => [
                'translationKey' => 'Dashboard_Dashboard',
            ],
            'order' => '0',
            'icon' => 'icon-reporting-dashboard',
            'help' => '<p>This is a dashboard page. Dashboards are a collection of Matomo\'s widgets that you add yourself to suit your specific needs. Mix and match any of Matomo\'s widgets to get the data <strong>*you*</strong> need at a glance.</p>',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => '1',
            'name' => [
                'translationKey' => 'Dashboard_Dashboard',
            ],
            'order' => '0',
            'help' => '',
        ],
        'widgets' => [
            0 => [
                'name' => '',
                'module' => [
                    'translationKey' => 'Dashboard_Dashboard',
                ],
                'action' => 'embeddedIndex',
                'order' => '99',
                'parameters' => [
                    'module' => [
                        'translationKey' => 'Dashboard_Dashboard',
                    ],
                    'action' => 'embeddedIndex',
                    'idDashboard' => '1',
                ],
                'uniqueId' => 'widgetDashboardembeddedIndexidDashboard1',
                'isWide' => '0',
            ],
        ],
    ],
    28 => [
        'uniqueId' => 'Referrers_Referrers.Referrers_AIAssistants',
        'category' => [
            'id' => 'Referrers_Referrers',
            'name' => [
                'translationKey' => 'Referrers_Acquisition',
            ],
            'order' => '15',
            'icon' => 'icon-reporting-referer',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'Referrers_AIAssistants',
            'name' => [
                'translationKey' => 'General_AIAssistants',
            ],
            'order' => '18',
            'help' => '<p>In this table, you can see which AI assistants referred visitors to your site.</p><p>By clicking on a row in the table, you can see which URLs the links to your website were on.</p>',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'General_AIAssistants',
                ],
                'module' => [
                    'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                ],
                'action' => 'getAIAssistants',
                'order' => '113',
                'parameters' => [
                    'module' => [
                        'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                    ],
                    'action' => 'getAIAssistants',
                ],
                'uniqueId' => 'widgetReferrersgetAIAssistants',
                'isWide' => '0',
                'viewDataTable' => 'tableAllColumns',
                'isReport' => '1',
            ],
        ],
    ],
    29 => [
        'uniqueId' => 'Referrers_Referrers.Referrers_WidgetGetAll',
        'category' => [
            'id' => 'Referrers_Referrers',
            'name' => [
                'translationKey' => 'Referrers_Acquisition',
            ],
            'order' => '15',
            'icon' => 'icon-reporting-referer',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'Referrers_WidgetGetAll',
            'name' => [
                'translationKey' => 'Referrers_WidgetGetAll',
            ],
            'order' => '5',
            'help' => '<p>This section shows you the number of visits that arrive from different channel types and referrers. Click on the plus or minus buttons to view the referrers within each type.</p><p>You can also analyse the number of actions performed by each of your traffic sources by enabling the table with Visitor engagement metrics view.</p>',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'Referrers_ReferrerTypes',
                ],
                'module' => [
                    'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                ],
                'action' => 'getReferrerType',
                'order' => '101',
                'parameters' => [
                    'module' => [
                        'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                    ],
                    'action' => 'getReferrerType',
                ],
                'uniqueId' => 'widgetReferrersgetReferrerType',
                'isWide' => '0',
                'viewDataTable' => 'tableAllColumns',
                'isReport' => '1',
            ],
            1 => [
                'name' => [
                    'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                ],
                'module' => [
                    'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                ],
                'action' => 'getAll',
                'order' => '102',
                'parameters' => [
                    'module' => [
                        'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                    ],
                    'action' => 'getAll',
                ],
                'uniqueId' => 'widgetReferrersgetAll',
                'isWide' => '0',
                'viewDataTable' => 'tableAllColumns',
                'isReport' => '1',
            ],
        ],
    ],
    30 => [
        'uniqueId' => 'Referrers_Referrers.Referrers_URLCampaignBuilder',
        'category' => [
            'id' => 'Referrers_Referrers',
            'name' => [
                'translationKey' => 'Referrers_Acquisition',
            ],
            'order' => '15',
            'icon' => 'icon-reporting-referer',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'Referrers_URLCampaignBuilder',
            'name' => [
                'translationKey' => 'Referrers_URLCampaignBuilder',
            ],
            'order' => '21',
            'help' => '',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'Referrers_URLCampaignBuilder',
                ],
                'module' => [
                    'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                ],
                'action' => 'getCampaignUrlBuilder',
                'order' => '99',
                'parameters' => [
                    'module' => [
                        'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                    ],
                    'action' => 'getCampaignUrlBuilder',
                ],
                'uniqueId' => 'widgetReferrersgetCampaignUrlBuilder',
                'isWide' => '0',
                'clientComponent' => [
                    'plugin' => [
                        'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                    ],
                    'name' => 'CampaignBuilderWidget',
                    'props' => [
                        'hasExtraPlugin' => '0',
                    ],
                ],
            ],
        ],
    ],
    31 => [
        'uniqueId' => 'Referrers_Referrers.Referrers_Campaigns',
        'category' => [
            'id' => 'Referrers_Referrers',
            'name' => [
                'translationKey' => 'Referrers_Acquisition',
            ],
            'order' => '15',
            'icon' => 'icon-reporting-referer',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'Referrers_Campaigns',
            'name' => [
                'translationKey' => 'Referrers_Campaigns',
            ],
            'order' => '20',
            'help' => '<p>The Campaign Tracking section allows you to analyse the visits associated with the various tracking values that have been linked to your digital campaigns. It can reveal things like, how much traffic your campaigns are bringing in, which creatives are performing best, how engaged campaign visitors are, and whether the campaign is resulting in sales or not.</p>',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'Referrers_Campaigns',
                ],
                'module' => [
                    'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                ],
                'action' => 'getCampaigns',
                'order' => '109',
                'parameters' => [
                    'module' => [
                        'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                    ],
                    'action' => 'getCampaigns',
                ],
                'uniqueId' => 'widgetReferrersgetCampaigns',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
        ],
    ],
    32 => [
        'uniqueId' => 'Referrers_Referrers.General_Overview',
        'category' => [
            'id' => 'Referrers_Referrers',
            'name' => [
                'translationKey' => 'Referrers_Acquisition',
            ],
            'order' => '15',
            'icon' => 'icon-reporting-referer',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'General_Overview',
            'name' => [
                'translationKey' => 'General_Overview',
            ],
            'order' => '2',
            'help' => '<p>The Acquisition Overview shows you the percentage of your traffic from all sources over a selected date range.</p><p>You can also click on a specific channel type to display it within the evolution graph. This can help you discover which channels contribute the most traffic to your site as well as any potential patterns over time. For example, a certain channel may perform better on weekends.</p>',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'General_EvolutionOverPeriod',
                ],
                'module' => [
                    'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                ],
                'action' => 'getEvolutionGraph',
                'order' => '9',
                'parameters' => [
                    'forceView' => '1',
                    'viewDataTable' => 'graphEvolution',
                    'module' => [
                        'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                    ],
                    'action' => 'getEvolutionGraph',
                    'columns' => [
                        0 => 'nb_visits',
                    ],
                ],
                'uniqueId' => 'widgetReferrersgetEvolutionGraphforceView1viewDataTablegraphEvolutioncolumnsArray',
                'isWide' => '0',
                'viewDataTable' => 'graphEvolution',
                'isReport' => '1',
            ],
            1 => [
                'name' => [
                    'translationKey' => 'Referrers_Type',
                ],
                'module' => [
                    'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                ],
                'action' => 'getSparklines',
                'order' => '10',
                'parameters' => [
                    'forceView' => '1',
                    'viewDataTable' => 'sparklines',
                    'module' => [
                        'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                    ],
                    'action' => 'getSparklines',
                ],
                'uniqueId' => 'widgetReferrersgetSparklinesforceView1viewDataTablesparklines',
                'isWide' => '0',
                'viewDataTable' => 'sparklines',
                'isReport' => '1',
            ],
        ],
    ],
    33 => [
        'uniqueId' => 'Referrers_Referrers.Referrers_SubmenuSearchEngines',
        'category' => [
            'id' => 'Referrers_Referrers',
            'name' => [
                'translationKey' => 'Referrers_Acquisition',
            ],
            'order' => '15',
            'icon' => 'icon-reporting-referer',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'Referrers_SubmenuSearchEngines',
            'name' => 'Search Engines & Keywords',
            'order' => '10',
            'help' => '<p>This section helps you analyse your search engine optimisation and performance. You can analyse your most popular keywords with the combined keyword reports or see which keywords perform well on specific search engines for more targeted analysis and optimisation.</p><p><a target="_blank" rel="noreferrer noopener" href="https://matomo.org/matomo-cloud/?mtm_campaign=Matomo_App&mtm_source=Matomo_App_OnPremise&mtm_medium=App.Referrers.getSearchEngines">Matomo Cloud</a> and <a target="_blank" rel="noreferrer noopener" href="https://plugins.matomo.org/SearchEngineKeywordsPerformance?mtm_campaign=Matomo_App&mtm_source=Matomo_App_OnPremise&mtm_medium=App.Referrers.getSearchEngines">Search Engine Keywords Performance</a> plugin users will receive the best results from this report.</p>',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'Marketplace_PluginKeywords',
                ],
                'module' => [
                    'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                ],
                'action' => 'getKeywords',
                'order' => '103',
                'parameters' => [
                    'module' => [
                        'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                    ],
                    'action' => 'getKeywords',
                ],
                'uniqueId' => 'widgetReferrersgetKeywords',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
            1 => [
                'name' => [
                    'translationKey' => 'Referrers_SearchEngines',
                ],
                'module' => [
                    'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                ],
                'action' => 'getSearchEngines',
                'order' => '107',
                'parameters' => [
                    'module' => [
                        'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                    ],
                    'action' => 'getSearchEngines',
                ],
                'uniqueId' => 'widgetReferrersgetSearchEngines',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
        ],
    ],
    34 => [
        'uniqueId' => 'Referrers_Referrers.Referrers_Socials',
        'category' => [
            'id' => 'Referrers_Referrers',
            'name' => [
                'translationKey' => 'Referrers_Acquisition',
            ],
            'order' => '15',
            'icon' => 'icon-reporting-referer',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'Referrers_Socials',
            'name' => [
                'translationKey' => 'Referrers_Socials',
            ],
            'order' => '16',
            'help' => '<p>In this table, you can see which websites referred visitors to your site.</p><p>By clicking on a row in the table, you can see which URLs the links to your website were on.</p>',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'Referrers_Socials',
                ],
                'module' => [
                    'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                ],
                'action' => 'getSocials',
                'order' => '111',
                'parameters' => [
                    'module' => [
                        'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                    ],
                    'action' => 'getSocials',
                ],
                'uniqueId' => 'widgetReferrersgetSocials',
                'isWide' => '0',
                'viewDataTable' => 'graphPie',
                'isReport' => '1',
            ],
        ],
    ],
    35 => [
        'uniqueId' => 'Referrers_Referrers.Referrers_SubmenuWebsitesOnly',
        'category' => [
            'id' => 'Referrers_Referrers',
            'name' => [
                'translationKey' => 'Referrers_Acquisition',
            ],
            'order' => '15',
            'icon' => 'icon-reporting-referer',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'Referrers_SubmenuWebsitesOnly',
            'name' => [
                'translationKey' => 'CorePluginsAdmin_Websites',
            ],
            'order' => '15',
            'help' => '<p>In this table, you can see which websites referred visitors to your site.</p><p>By clicking on a row in the table, you can see which URLs the links to your website were on.</p>',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'CorePluginsAdmin_Websites',
                ],
                'module' => [
                    'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                ],
                'action' => 'getWebsites',
                'order' => '105',
                'parameters' => [
                    'module' => [
                        'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                    ],
                    'action' => 'getWebsites',
                ],
                'uniqueId' => 'widgetReferrersgetWebsites',
                'isWide' => '0',
                'viewDataTable' => 'tableAllColumns',
                'isReport' => '1',
            ],
        ],
    ],
    36 => [
        'uniqueId' => 'Goals_Goals.1',
        'category' => [
            'id' => 'Goals_Goals',
            'name' => [
                'translationKey' => 'Goals_Goals',
            ],
            'order' => '25',
            'icon' => 'icon-reporting-goal',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => '1',
            'name' => 'Goal 1 - Thank you',
            'order' => '900',
            'help' => '',
        ],
        'widgets' => [
            0 => [
                'name' => 'Goal 1 - Thank you',
                'module' => 'CoreHome',
                'action' => 'renderWidgetContainer',
                'order' => '2',
                'parameters' => [
                    'module' => 'CoreHome',
                    'action' => 'renderWidgetContainer',
                    'containerId' => 'Goal_1',
                ],
                'uniqueId' => 'widgetGoal_1',
                'isWide' => '0',
                'layout' => '',
                'isContainer' => '1',
                'widgets' => [
                    0 => [
                        'name' => '',
                        'category' => [
                            'id' => 'Goals_Goals',
                            'name' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => '1',
                            'name' => '1',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_Goals',
                        ],
                        'action' => 'getEvolutionGraph',
                        'order' => '5',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'graphEvolution',
                            'module' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'action' => 'getEvolutionGraph',
                            'idGoal' => '1',
                        ],
                        'uniqueId' => 'widgetGoalsgetEvolutionGraphforceView1viewDataTablegraphEvolutionidGoal1',
                        'isWide' => '0',
                        'viewDataTable' => 'graphEvolution',
                        'isReport' => '1',
                    ],
                    1 => [
                        'name' => '',
                        'category' => [
                            'id' => 'Goals_Goals',
                            'name' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => '1',
                            'name' => '1',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_Goals',
                        ],
                        'action' => 'get',
                        'order' => '15',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'sparklines',
                            'module' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'action' => 'get',
                            'idGoal' => '1',
                            'allow_multiple' => '1',
                        ],
                        'uniqueId' => 'widgetGoalsgetforceView1viewDataTablesparklinesidGoal1allow_multiple1',
                        'isWide' => '0',
                        'viewDataTable' => 'sparklines',
                        'isReport' => '1',
                    ],
                    2 => [
                        'name' => [
                            'translationKey' => 'Goals_ConversionsOverview',
                        ],
                        'category' => [
                            'id' => 'Goals_Goals',
                            'name' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => '1',
                            'name' => '1',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_Goals',
                        ],
                        'action' => 'goalConversionsOverview',
                        'order' => '25',
                        'parameters' => [
                            'module' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'action' => 'goalConversionsOverview',
                            'idGoal' => '1',
                        ],
                        'uniqueId' => 'widgetGoalsgoalConversionsOverviewidGoal1',
                        'isWide' => '0',
                        'middlewareParameters' => [
                            'module' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'action' => 'hasConversions',
                            'idGoal' => '1',
                        ],
                        'viewDataTable' => 'table',
                        'isReport' => '1',
                    ],
                ],
            ],
            1 => [
                'name' => 'Goal Goal 1 - Thank you conversions by type of visit',
                'module' => 'CoreHome',
                'action' => 'renderWidgetContainer',
                'order' => '35',
                'parameters' => [
                    'module' => 'CoreHome',
                    'action' => 'renderWidgetContainer',
                    'containerId' => 'Goals1',
                ],
                'uniqueId' => 'widgetGoals1',
                'isWide' => '0',
                'middlewareParameters' => [
                    'module' => [
                        'translationKey' => 'Goals_Goals',
                    ],
                    'action' => 'hasConversions',
                    'idGoal' => '1',
                ],
                'layout' => 'ByDimension',
                'isContainer' => '1',
                'widgets' => [
                    0 => [
                        'name' => [
                            'translationKey' => 'UserCountry_Country',
                        ],
                        'category' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'UserCountry',
                        'action' => 'getCountry',
                        'order' => '301',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'UserCountry',
                            'action' => 'getCountry',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '1',
                        ],
                        'uniqueId' => 'widgetUserCountrygetCountryforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal1',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    1 => [
                        'name' => [
                            'translationKey' => 'UserCountry_Continent',
                        ],
                        'category' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'UserCountry',
                        'action' => 'getContinent',
                        'order' => '302',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'UserCountry',
                            'action' => 'getContinent',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '1',
                        ],
                        'uniqueId' => 'widgetUserCountrygetContinentforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal1',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    2 => [
                        'name' => [
                            'translationKey' => 'UserCountry_Region',
                        ],
                        'category' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'UserCountry',
                        'action' => 'getRegion',
                        'order' => '303',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'UserCountry',
                            'action' => 'getRegion',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '1',
                        ],
                        'uniqueId' => 'widgetUserCountrygetRegionforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal1',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    3 => [
                        'name' => [
                            'translationKey' => 'UserCountry_City',
                        ],
                        'category' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'UserCountry',
                        'action' => 'getCity',
                        'order' => '304',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'UserCountry',
                            'action' => 'getCity',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '1',
                        ],
                        'uniqueId' => 'widgetUserCountrygetCityforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal1',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    4 => [
                        'name' => [
                            'translationKey' => 'DevicesDetection_DeviceType',
                        ],
                        'category' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'DevicesDetection',
                        'action' => 'getType',
                        'order' => '305',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'DevicesDetection',
                            'action' => 'getType',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '1',
                        ],
                        'uniqueId' => 'widgetDevicesDetectiongetTypeforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal1',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    5 => [
                        'name' => [
                            'translationKey' => 'DevicesDetection_DeviceModel',
                        ],
                        'category' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'DevicesDetection',
                        'action' => 'getModel',
                        'order' => '306',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'DevicesDetection',
                            'action' => 'getModel',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '1',
                        ],
                        'uniqueId' => 'widgetDevicesDetectiongetModelforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal1',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    6 => [
                        'name' => [
                            'translationKey' => 'DevicesDetection_DeviceBrand',
                        ],
                        'category' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'DevicesDetection',
                        'action' => 'getBrand',
                        'order' => '307',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'DevicesDetection',
                            'action' => 'getBrand',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '1',
                        ],
                        'uniqueId' => 'widgetDevicesDetectiongetBrandforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal1',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    7 => [
                        'name' => [
                            'translationKey' => 'DevicesDetection_Browsers',
                        ],
                        'category' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'DevicesDetection',
                        'action' => 'getBrowsers',
                        'order' => '308',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'DevicesDetection',
                            'action' => 'getBrowsers',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '1',
                        ],
                        'uniqueId' => 'widgetDevicesDetectiongetBrowsersforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal1',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    8 => [
                        'name' => [
                            'translationKey' => 'VisitTime_SiteTime',
                        ],
                        'category' => [
                            'id' => 'Goals by User attribute',
                            'name' => 'Goals by User attribute',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User attribute',
                            'name' => 'Goals by User attribute',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'VisitTime',
                        'action' => 'getVisitInformationPerServerTime',
                        'order' => '401',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'VisitTime',
                            'action' => 'getVisitInformationPerServerTime',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '1',
                        ],
                        'uniqueId' => 'widgetVisitTimegetVisitInformationPerServerTimeforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal1',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    9 => [
                        'name' => 'Custom Variables',
                        'category' => [
                            'id' => 'Goals by User attribute',
                            'name' => 'Goals by User attribute',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User attribute',
                            'name' => 'Goals by User attribute',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'CustomVariables',
                        'action' => 'getCustomVariables',
                        'order' => '402',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'CustomVariables',
                            'action' => 'getCustomVariables',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '1',
                        ],
                        'uniqueId' => 'widgetCustomVariablesgetCustomVariablesforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal1',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    10 => [
                        'name' => [
                            'translationKey' => 'Actions_PageUrls',
                        ],
                        'category' => [
                            'id' => 'Goals by Pages',
                            'name' => 'Goals by Pages',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Pages',
                            'name' => 'Goals by Pages',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'General_Actions',
                        ],
                        'action' => 'getPageUrls',
                        'order' => '101',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'General_Actions',
                            ],
                            'action' => 'getPageUrls',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '1',
                        ],
                        'uniqueId' => 'widgetActionsgetPageUrlsforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal1',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    11 => [
                        'name' => [
                            'translationKey' => 'Actions_SubmenuPagesEntry',
                        ],
                        'category' => [
                            'id' => 'Goals by Pages',
                            'name' => 'Goals by Pages',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Pages',
                            'name' => 'Goals by Pages',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'General_Actions',
                        ],
                        'action' => 'getEntryPageUrls',
                        'order' => '102',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'General_Actions',
                            ],
                            'action' => 'getEntryPageUrls',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '1',
                        ],
                        'uniqueId' => 'widgetActionsgetEntryPageUrlsforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal1',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    12 => [
                        'name' => [
                            'translationKey' => 'Actions_EntryPageTitles',
                        ],
                        'category' => [
                            'id' => 'Goals by Pages',
                            'name' => 'Goals by Pages',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Pages',
                            'name' => 'Goals by Pages',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'General_Actions',
                        ],
                        'action' => 'getEntryPageTitles',
                        'order' => '103',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'General_Actions',
                            ],
                            'action' => 'getEntryPageTitles',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '1',
                        ],
                        'uniqueId' => 'widgetActionsgetEntryPageTitlesforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal1',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    13 => [
                        'name' => [
                            'translationKey' => 'Actions_SubmenuPageTitles',
                        ],
                        'category' => [
                            'id' => 'Goals by Pages',
                            'name' => 'Goals by Pages',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Pages',
                            'name' => 'Goals by Pages',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'General_Actions',
                        ],
                        'action' => 'getPageTitles',
                        'order' => '104',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'General_Actions',
                            ],
                            'action' => 'getPageTitles',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '1',
                        ],
                        'uniqueId' => 'widgetActionsgetPageTitlesforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal1',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    14 => [
                        'name' => [
                            'translationKey' => 'Referrers_Type',
                        ],
                        'category' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                        ],
                        'action' => 'getReferrerType',
                        'order' => '1',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                            ],
                            'action' => 'getReferrerType',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '1',
                        ],
                        'uniqueId' => 'widgetReferrersgetReferrerTypeforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal1',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    15 => [
                        'name' => [
                            'translationKey' => 'Marketplace_PluginKeywords',
                        ],
                        'category' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                        ],
                        'action' => 'getKeywords',
                        'order' => '2',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                            ],
                            'action' => 'getKeywords',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '1',
                        ],
                        'uniqueId' => 'widgetReferrersgetKeywordsforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal1',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    16 => [
                        'name' => [
                            'translationKey' => 'Referrers_SearchEngines',
                        ],
                        'category' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                        ],
                        'action' => 'getSearchEngines',
                        'order' => '3',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                            ],
                            'action' => 'getSearchEngines',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '1',
                        ],
                        'uniqueId' => 'widgetReferrersgetSearchEnginesforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal1',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    17 => [
                        'name' => [
                            'translationKey' => 'CorePluginsAdmin_Websites',
                        ],
                        'category' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                        ],
                        'action' => 'getWebsites',
                        'order' => '4',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                            ],
                            'action' => 'getWebsites',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '1',
                        ],
                        'uniqueId' => 'widgetReferrersgetWebsitesforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal1',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    18 => [
                        'name' => [
                            'translationKey' => 'Referrers_Socials',
                        ],
                        'category' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                        ],
                        'action' => 'getSocials',
                        'order' => '5',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                            ],
                            'action' => 'getSocials',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '1',
                        ],
                        'uniqueId' => 'widgetReferrersgetSocialsforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal1',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    19 => [
                        'name' => [
                            'translationKey' => 'General_AIAssistants',
                        ],
                        'category' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                        ],
                        'action' => 'getAIAssistants',
                        'order' => '6',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                            ],
                            'action' => 'getAIAssistants',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '1',
                        ],
                        'uniqueId' => 'widgetReferrersgetAIAssistantsforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal1',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    20 => [
                        'name' => [
                            'translationKey' => 'Referrers_Campaigns',
                        ],
                        'category' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                        ],
                        'action' => 'getCampaigns',
                        'order' => '7',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                            ],
                            'action' => 'getCampaigns',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '1',
                        ],
                        'uniqueId' => 'widgetReferrersgetCampaignsforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal1',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    21 => [
                        'name' => [
                            'translationKey' => 'Goals_VisitsUntilConv',
                        ],
                        'category' => [
                            'id' => 'Goals engagement',
                            'name' => 'Goals engagement',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals engagement',
                            'name' => 'Goals engagement',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_Goals',
                        ],
                        'action' => 'getVisitsUntilConversion',
                        'order' => '201',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'table',
                            'module' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'action' => 'getVisitsUntilConversion',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '1',
                        ],
                        'uniqueId' => 'widgetGoalsgetVisitsUntilConversionforceView1viewDataTabletabledocumentationForGoalsPage1idGoal1',
                        'isWide' => '0',
                        'viewDataTable' => 'table',
                        'isReport' => '1',
                    ],
                    22 => [
                        'name' => [
                            'translationKey' => 'Goals_DaysToConv',
                        ],
                        'category' => [
                            'id' => 'Goals engagement',
                            'name' => 'Goals engagement',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals engagement',
                            'name' => 'Goals engagement',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_Goals',
                        ],
                        'action' => 'getDaysToConversion',
                        'order' => '202',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'table',
                            'module' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'action' => 'getDaysToConversion',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '1',
                        ],
                        'uniqueId' => 'widgetGoalsgetDaysToConversionforceView1viewDataTabletabledocumentationForGoalsPage1idGoal1',
                        'isWide' => '0',
                        'viewDataTable' => 'table',
                        'isReport' => '1',
                    ],
                ],
            ],
        ],
    ],
    37 => [
        'uniqueId' => 'Goals_Goals.2',
        'category' => [
            'id' => 'Goals_Goals',
            'name' => [
                'translationKey' => 'Goals_Goals',
            ],
            'order' => '25',
            'icon' => 'icon-reporting-goal',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => '2',
            'name' => 'Goal 2 - Hello',
            'order' => '901',
            'help' => '',
        ],
        'widgets' => [
            0 => [
                'name' => 'Goal 2 - Hello',
                'module' => 'CoreHome',
                'action' => 'renderWidgetContainer',
                'order' => '3',
                'parameters' => [
                    'module' => 'CoreHome',
                    'action' => 'renderWidgetContainer',
                    'containerId' => 'Goal_2',
                ],
                'uniqueId' => 'widgetGoal_2',
                'isWide' => '0',
                'layout' => '',
                'isContainer' => '1',
                'widgets' => [
                    0 => [
                        'name' => '',
                        'category' => [
                            'id' => 'Goals_Goals',
                            'name' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => '2',
                            'name' => '2',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_Goals',
                        ],
                        'action' => 'getEvolutionGraph',
                        'order' => '5',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'graphEvolution',
                            'module' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'action' => 'getEvolutionGraph',
                            'idGoal' => '2',
                        ],
                        'uniqueId' => 'widgetGoalsgetEvolutionGraphforceView1viewDataTablegraphEvolutionidGoal2',
                        'isWide' => '0',
                        'viewDataTable' => 'graphEvolution',
                        'isReport' => '1',
                    ],
                    1 => [
                        'name' => '',
                        'category' => [
                            'id' => 'Goals_Goals',
                            'name' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => '2',
                            'name' => '2',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_Goals',
                        ],
                        'action' => 'get',
                        'order' => '15',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'sparklines',
                            'module' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'action' => 'get',
                            'idGoal' => '2',
                            'allow_multiple' => '0',
                        ],
                        'uniqueId' => 'widgetGoalsgetforceView1viewDataTablesparklinesidGoal2allow_multiple0',
                        'isWide' => '0',
                        'viewDataTable' => 'sparklines',
                        'isReport' => '1',
                    ],
                    2 => [
                        'name' => [
                            'translationKey' => 'Goals_ConversionsOverview',
                        ],
                        'category' => [
                            'id' => 'Goals_Goals',
                            'name' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => '2',
                            'name' => '2',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_Goals',
                        ],
                        'action' => 'goalConversionsOverview',
                        'order' => '25',
                        'parameters' => [
                            'module' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'action' => 'goalConversionsOverview',
                            'idGoal' => '2',
                        ],
                        'uniqueId' => 'widgetGoalsgoalConversionsOverviewidGoal2',
                        'isWide' => '0',
                        'middlewareParameters' => [
                            'module' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'action' => 'hasConversions',
                            'idGoal' => '2',
                        ],
                        'viewDataTable' => 'table',
                        'isReport' => '1',
                    ],
                ],
            ],
            1 => [
                'name' => 'Goal Goal 2 - Hello conversions by type of visit',
                'module' => 'CoreHome',
                'action' => 'renderWidgetContainer',
                'order' => '35',
                'parameters' => [
                    'module' => 'CoreHome',
                    'action' => 'renderWidgetContainer',
                    'containerId' => 'Goals2',
                ],
                'uniqueId' => 'widgetGoals2',
                'isWide' => '0',
                'middlewareParameters' => [
                    'module' => [
                        'translationKey' => 'Goals_Goals',
                    ],
                    'action' => 'hasConversions',
                    'idGoal' => '2',
                ],
                'layout' => 'ByDimension',
                'isContainer' => '1',
                'widgets' => [
                    0 => [
                        'name' => [
                            'translationKey' => 'UserCountry_Country',
                        ],
                        'category' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'UserCountry',
                        'action' => 'getCountry',
                        'order' => '301',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'UserCountry',
                            'action' => 'getCountry',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '2',
                        ],
                        'uniqueId' => 'widgetUserCountrygetCountryforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal2',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    1 => [
                        'name' => [
                            'translationKey' => 'UserCountry_Continent',
                        ],
                        'category' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'UserCountry',
                        'action' => 'getContinent',
                        'order' => '302',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'UserCountry',
                            'action' => 'getContinent',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '2',
                        ],
                        'uniqueId' => 'widgetUserCountrygetContinentforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal2',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    2 => [
                        'name' => [
                            'translationKey' => 'UserCountry_Region',
                        ],
                        'category' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'UserCountry',
                        'action' => 'getRegion',
                        'order' => '303',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'UserCountry',
                            'action' => 'getRegion',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '2',
                        ],
                        'uniqueId' => 'widgetUserCountrygetRegionforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal2',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    3 => [
                        'name' => [
                            'translationKey' => 'UserCountry_City',
                        ],
                        'category' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'UserCountry',
                        'action' => 'getCity',
                        'order' => '304',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'UserCountry',
                            'action' => 'getCity',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '2',
                        ],
                        'uniqueId' => 'widgetUserCountrygetCityforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal2',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    4 => [
                        'name' => [
                            'translationKey' => 'DevicesDetection_DeviceType',
                        ],
                        'category' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'DevicesDetection',
                        'action' => 'getType',
                        'order' => '305',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'DevicesDetection',
                            'action' => 'getType',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '2',
                        ],
                        'uniqueId' => 'widgetDevicesDetectiongetTypeforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal2',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    5 => [
                        'name' => [
                            'translationKey' => 'DevicesDetection_DeviceModel',
                        ],
                        'category' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'DevicesDetection',
                        'action' => 'getModel',
                        'order' => '306',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'DevicesDetection',
                            'action' => 'getModel',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '2',
                        ],
                        'uniqueId' => 'widgetDevicesDetectiongetModelforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal2',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    6 => [
                        'name' => [
                            'translationKey' => 'DevicesDetection_DeviceBrand',
                        ],
                        'category' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'DevicesDetection',
                        'action' => 'getBrand',
                        'order' => '307',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'DevicesDetection',
                            'action' => 'getBrand',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '2',
                        ],
                        'uniqueId' => 'widgetDevicesDetectiongetBrandforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal2',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    7 => [
                        'name' => [
                            'translationKey' => 'DevicesDetection_Browsers',
                        ],
                        'category' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'DevicesDetection',
                        'action' => 'getBrowsers',
                        'order' => '308',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'DevicesDetection',
                            'action' => 'getBrowsers',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '2',
                        ],
                        'uniqueId' => 'widgetDevicesDetectiongetBrowsersforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal2',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    8 => [
                        'name' => [
                            'translationKey' => 'VisitTime_SiteTime',
                        ],
                        'category' => [
                            'id' => 'Goals by User attribute',
                            'name' => 'Goals by User attribute',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User attribute',
                            'name' => 'Goals by User attribute',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'VisitTime',
                        'action' => 'getVisitInformationPerServerTime',
                        'order' => '401',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'VisitTime',
                            'action' => 'getVisitInformationPerServerTime',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '2',
                        ],
                        'uniqueId' => 'widgetVisitTimegetVisitInformationPerServerTimeforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal2',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    9 => [
                        'name' => 'Custom Variables',
                        'category' => [
                            'id' => 'Goals by User attribute',
                            'name' => 'Goals by User attribute',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User attribute',
                            'name' => 'Goals by User attribute',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'CustomVariables',
                        'action' => 'getCustomVariables',
                        'order' => '402',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'CustomVariables',
                            'action' => 'getCustomVariables',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '2',
                        ],
                        'uniqueId' => 'widgetCustomVariablesgetCustomVariablesforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal2',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    10 => [
                        'name' => [
                            'translationKey' => 'Actions_PageUrls',
                        ],
                        'category' => [
                            'id' => 'Goals by Pages',
                            'name' => 'Goals by Pages',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Pages',
                            'name' => 'Goals by Pages',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'General_Actions',
                        ],
                        'action' => 'getPageUrls',
                        'order' => '101',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'General_Actions',
                            ],
                            'action' => 'getPageUrls',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '2',
                        ],
                        'uniqueId' => 'widgetActionsgetPageUrlsforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal2',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    11 => [
                        'name' => [
                            'translationKey' => 'Actions_SubmenuPagesEntry',
                        ],
                        'category' => [
                            'id' => 'Goals by Pages',
                            'name' => 'Goals by Pages',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Pages',
                            'name' => 'Goals by Pages',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'General_Actions',
                        ],
                        'action' => 'getEntryPageUrls',
                        'order' => '102',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'General_Actions',
                            ],
                            'action' => 'getEntryPageUrls',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '2',
                        ],
                        'uniqueId' => 'widgetActionsgetEntryPageUrlsforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal2',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    12 => [
                        'name' => [
                            'translationKey' => 'Actions_EntryPageTitles',
                        ],
                        'category' => [
                            'id' => 'Goals by Pages',
                            'name' => 'Goals by Pages',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Pages',
                            'name' => 'Goals by Pages',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'General_Actions',
                        ],
                        'action' => 'getEntryPageTitles',
                        'order' => '103',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'General_Actions',
                            ],
                            'action' => 'getEntryPageTitles',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '2',
                        ],
                        'uniqueId' => 'widgetActionsgetEntryPageTitlesforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal2',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    13 => [
                        'name' => [
                            'translationKey' => 'Actions_SubmenuPageTitles',
                        ],
                        'category' => [
                            'id' => 'Goals by Pages',
                            'name' => 'Goals by Pages',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Pages',
                            'name' => 'Goals by Pages',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'General_Actions',
                        ],
                        'action' => 'getPageTitles',
                        'order' => '104',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'General_Actions',
                            ],
                            'action' => 'getPageTitles',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '2',
                        ],
                        'uniqueId' => 'widgetActionsgetPageTitlesforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal2',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    14 => [
                        'name' => [
                            'translationKey' => 'Referrers_Type',
                        ],
                        'category' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                        ],
                        'action' => 'getReferrerType',
                        'order' => '1',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                            ],
                            'action' => 'getReferrerType',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '2',
                        ],
                        'uniqueId' => 'widgetReferrersgetReferrerTypeforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal2',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    15 => [
                        'name' => [
                            'translationKey' => 'Marketplace_PluginKeywords',
                        ],
                        'category' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                        ],
                        'action' => 'getKeywords',
                        'order' => '2',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                            ],
                            'action' => 'getKeywords',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '2',
                        ],
                        'uniqueId' => 'widgetReferrersgetKeywordsforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal2',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    16 => [
                        'name' => [
                            'translationKey' => 'Referrers_SearchEngines',
                        ],
                        'category' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                        ],
                        'action' => 'getSearchEngines',
                        'order' => '3',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                            ],
                            'action' => 'getSearchEngines',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '2',
                        ],
                        'uniqueId' => 'widgetReferrersgetSearchEnginesforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal2',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    17 => [
                        'name' => [
                            'translationKey' => 'CorePluginsAdmin_Websites',
                        ],
                        'category' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                        ],
                        'action' => 'getWebsites',
                        'order' => '4',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                            ],
                            'action' => 'getWebsites',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '2',
                        ],
                        'uniqueId' => 'widgetReferrersgetWebsitesforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal2',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    18 => [
                        'name' => [
                            'translationKey' => 'Referrers_Socials',
                        ],
                        'category' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                        ],
                        'action' => 'getSocials',
                        'order' => '5',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                            ],
                            'action' => 'getSocials',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '2',
                        ],
                        'uniqueId' => 'widgetReferrersgetSocialsforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal2',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    19 => [
                        'name' => [
                            'translationKey' => 'General_AIAssistants',
                        ],
                        'category' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                        ],
                        'action' => 'getAIAssistants',
                        'order' => '6',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                            ],
                            'action' => 'getAIAssistants',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '2',
                        ],
                        'uniqueId' => 'widgetReferrersgetAIAssistantsforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal2',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    20 => [
                        'name' => [
                            'translationKey' => 'Referrers_Campaigns',
                        ],
                        'category' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                        ],
                        'action' => 'getCampaigns',
                        'order' => '7',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                            ],
                            'action' => 'getCampaigns',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '2',
                        ],
                        'uniqueId' => 'widgetReferrersgetCampaignsforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal2',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    21 => [
                        'name' => [
                            'translationKey' => 'Goals_VisitsUntilConv',
                        ],
                        'category' => [
                            'id' => 'Goals engagement',
                            'name' => 'Goals engagement',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals engagement',
                            'name' => 'Goals engagement',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_Goals',
                        ],
                        'action' => 'getVisitsUntilConversion',
                        'order' => '201',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'table',
                            'module' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'action' => 'getVisitsUntilConversion',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '2',
                        ],
                        'uniqueId' => 'widgetGoalsgetVisitsUntilConversionforceView1viewDataTabletabledocumentationForGoalsPage1idGoal2',
                        'isWide' => '0',
                        'viewDataTable' => 'table',
                        'isReport' => '1',
                    ],
                    22 => [
                        'name' => [
                            'translationKey' => 'Goals_DaysToConv',
                        ],
                        'category' => [
                            'id' => 'Goals engagement',
                            'name' => 'Goals engagement',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals engagement',
                            'name' => 'Goals engagement',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_Goals',
                        ],
                        'action' => 'getDaysToConversion',
                        'order' => '202',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'table',
                            'module' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'action' => 'getDaysToConversion',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '2',
                        ],
                        'uniqueId' => 'widgetGoalsgetDaysToConversionforceView1viewDataTabletabledocumentationForGoalsPage1idGoal2',
                        'isWide' => '0',
                        'viewDataTable' => 'table',
                        'isReport' => '1',
                    ],
                ],
            ],
        ],
    ],
    38 => [
        'uniqueId' => 'Goals_Goals.3',
        'category' => [
            'id' => 'Goals_Goals',
            'name' => [
                'translationKey' => 'Goals_Goals',
            ],
            'order' => '25',
            'icon' => 'icon-reporting-goal',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => '3',
            'name' => 'triggered js',
            'order' => '902',
            'help' => '',
        ],
        'widgets' => [
            0 => [
                'name' => 'triggered js',
                'module' => 'CoreHome',
                'action' => 'renderWidgetContainer',
                'order' => '4',
                'parameters' => [
                    'module' => 'CoreHome',
                    'action' => 'renderWidgetContainer',
                    'containerId' => 'Goal_3',
                ],
                'uniqueId' => 'widgetGoal_3',
                'isWide' => '0',
                'layout' => '',
                'isContainer' => '1',
                'widgets' => [
                    0 => [
                        'name' => '',
                        'category' => [
                            'id' => 'Goals_Goals',
                            'name' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => '3',
                            'name' => '3',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_Goals',
                        ],
                        'action' => 'getEvolutionGraph',
                        'order' => '5',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'graphEvolution',
                            'module' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'action' => 'getEvolutionGraph',
                            'idGoal' => '3',
                        ],
                        'uniqueId' => 'widgetGoalsgetEvolutionGraphforceView1viewDataTablegraphEvolutionidGoal3',
                        'isWide' => '0',
                        'viewDataTable' => 'graphEvolution',
                        'isReport' => '1',
                    ],
                    1 => [
                        'name' => '',
                        'category' => [
                            'id' => 'Goals_Goals',
                            'name' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => '3',
                            'name' => '3',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_Goals',
                        ],
                        'action' => 'get',
                        'order' => '15',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'sparklines',
                            'module' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'action' => 'get',
                            'idGoal' => '3',
                            'allow_multiple' => '0',
                        ],
                        'uniqueId' => 'widgetGoalsgetforceView1viewDataTablesparklinesidGoal3allow_multiple0',
                        'isWide' => '0',
                        'viewDataTable' => 'sparklines',
                        'isReport' => '1',
                    ],
                    2 => [
                        'name' => [
                            'translationKey' => 'Goals_ConversionsOverview',
                        ],
                        'category' => [
                            'id' => 'Goals_Goals',
                            'name' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => '3',
                            'name' => '3',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_Goals',
                        ],
                        'action' => 'goalConversionsOverview',
                        'order' => '25',
                        'parameters' => [
                            'module' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'action' => 'goalConversionsOverview',
                            'idGoal' => '3',
                        ],
                        'uniqueId' => 'widgetGoalsgoalConversionsOverviewidGoal3',
                        'isWide' => '0',
                        'middlewareParameters' => [
                            'module' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'action' => 'hasConversions',
                            'idGoal' => '3',
                        ],
                        'viewDataTable' => 'table',
                        'isReport' => '1',
                    ],
                ],
            ],
            1 => [
                'name' => 'Goal triggered js conversions by type of visit',
                'module' => 'CoreHome',
                'action' => 'renderWidgetContainer',
                'order' => '35',
                'parameters' => [
                    'module' => 'CoreHome',
                    'action' => 'renderWidgetContainer',
                    'containerId' => 'Goals3',
                ],
                'uniqueId' => 'widgetGoals3',
                'isWide' => '0',
                'middlewareParameters' => [
                    'module' => [
                        'translationKey' => 'Goals_Goals',
                    ],
                    'action' => 'hasConversions',
                    'idGoal' => '3',
                ],
                'layout' => 'ByDimension',
                'isContainer' => '1',
                'widgets' => [
                    0 => [
                        'name' => [
                            'translationKey' => 'UserCountry_Country',
                        ],
                        'category' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'UserCountry',
                        'action' => 'getCountry',
                        'order' => '301',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'UserCountry',
                            'action' => 'getCountry',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '3',
                        ],
                        'uniqueId' => 'widgetUserCountrygetCountryforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal3',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    1 => [
                        'name' => [
                            'translationKey' => 'UserCountry_Continent',
                        ],
                        'category' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'UserCountry',
                        'action' => 'getContinent',
                        'order' => '302',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'UserCountry',
                            'action' => 'getContinent',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '3',
                        ],
                        'uniqueId' => 'widgetUserCountrygetContinentforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal3',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    2 => [
                        'name' => [
                            'translationKey' => 'UserCountry_Region',
                        ],
                        'category' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'UserCountry',
                        'action' => 'getRegion',
                        'order' => '303',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'UserCountry',
                            'action' => 'getRegion',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '3',
                        ],
                        'uniqueId' => 'widgetUserCountrygetRegionforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal3',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    3 => [
                        'name' => [
                            'translationKey' => 'UserCountry_City',
                        ],
                        'category' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'UserCountry',
                        'action' => 'getCity',
                        'order' => '304',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'UserCountry',
                            'action' => 'getCity',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '3',
                        ],
                        'uniqueId' => 'widgetUserCountrygetCityforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal3',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    4 => [
                        'name' => [
                            'translationKey' => 'DevicesDetection_DeviceType',
                        ],
                        'category' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'DevicesDetection',
                        'action' => 'getType',
                        'order' => '305',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'DevicesDetection',
                            'action' => 'getType',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '3',
                        ],
                        'uniqueId' => 'widgetDevicesDetectiongetTypeforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal3',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    5 => [
                        'name' => [
                            'translationKey' => 'DevicesDetection_DeviceModel',
                        ],
                        'category' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'DevicesDetection',
                        'action' => 'getModel',
                        'order' => '306',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'DevicesDetection',
                            'action' => 'getModel',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '3',
                        ],
                        'uniqueId' => 'widgetDevicesDetectiongetModelforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal3',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    6 => [
                        'name' => [
                            'translationKey' => 'DevicesDetection_DeviceBrand',
                        ],
                        'category' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'DevicesDetection',
                        'action' => 'getBrand',
                        'order' => '307',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'DevicesDetection',
                            'action' => 'getBrand',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '3',
                        ],
                        'uniqueId' => 'widgetDevicesDetectiongetBrandforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal3',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    7 => [
                        'name' => [
                            'translationKey' => 'DevicesDetection_Browsers',
                        ],
                        'category' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'DevicesDetection',
                        'action' => 'getBrowsers',
                        'order' => '308',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'DevicesDetection',
                            'action' => 'getBrowsers',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '3',
                        ],
                        'uniqueId' => 'widgetDevicesDetectiongetBrowsersforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal3',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    8 => [
                        'name' => [
                            'translationKey' => 'VisitTime_SiteTime',
                        ],
                        'category' => [
                            'id' => 'Goals by User attribute',
                            'name' => 'Goals by User attribute',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User attribute',
                            'name' => 'Goals by User attribute',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'VisitTime',
                        'action' => 'getVisitInformationPerServerTime',
                        'order' => '401',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'VisitTime',
                            'action' => 'getVisitInformationPerServerTime',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '3',
                        ],
                        'uniqueId' => 'widgetVisitTimegetVisitInformationPerServerTimeforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal3',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    9 => [
                        'name' => 'Custom Variables',
                        'category' => [
                            'id' => 'Goals by User attribute',
                            'name' => 'Goals by User attribute',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User attribute',
                            'name' => 'Goals by User attribute',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'CustomVariables',
                        'action' => 'getCustomVariables',
                        'order' => '402',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'CustomVariables',
                            'action' => 'getCustomVariables',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '3',
                        ],
                        'uniqueId' => 'widgetCustomVariablesgetCustomVariablesforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal3',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    10 => [
                        'name' => [
                            'translationKey' => 'Actions_PageUrls',
                        ],
                        'category' => [
                            'id' => 'Goals by Pages',
                            'name' => 'Goals by Pages',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Pages',
                            'name' => 'Goals by Pages',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'General_Actions',
                        ],
                        'action' => 'getPageUrls',
                        'order' => '101',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'General_Actions',
                            ],
                            'action' => 'getPageUrls',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '3',
                        ],
                        'uniqueId' => 'widgetActionsgetPageUrlsforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal3',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    11 => [
                        'name' => [
                            'translationKey' => 'Actions_SubmenuPagesEntry',
                        ],
                        'category' => [
                            'id' => 'Goals by Pages',
                            'name' => 'Goals by Pages',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Pages',
                            'name' => 'Goals by Pages',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'General_Actions',
                        ],
                        'action' => 'getEntryPageUrls',
                        'order' => '102',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'General_Actions',
                            ],
                            'action' => 'getEntryPageUrls',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '3',
                        ],
                        'uniqueId' => 'widgetActionsgetEntryPageUrlsforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal3',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    12 => [
                        'name' => [
                            'translationKey' => 'Actions_EntryPageTitles',
                        ],
                        'category' => [
                            'id' => 'Goals by Pages',
                            'name' => 'Goals by Pages',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Pages',
                            'name' => 'Goals by Pages',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'General_Actions',
                        ],
                        'action' => 'getEntryPageTitles',
                        'order' => '103',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'General_Actions',
                            ],
                            'action' => 'getEntryPageTitles',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '3',
                        ],
                        'uniqueId' => 'widgetActionsgetEntryPageTitlesforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal3',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    13 => [
                        'name' => [
                            'translationKey' => 'Actions_SubmenuPageTitles',
                        ],
                        'category' => [
                            'id' => 'Goals by Pages',
                            'name' => 'Goals by Pages',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Pages',
                            'name' => 'Goals by Pages',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'General_Actions',
                        ],
                        'action' => 'getPageTitles',
                        'order' => '104',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'General_Actions',
                            ],
                            'action' => 'getPageTitles',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '3',
                        ],
                        'uniqueId' => 'widgetActionsgetPageTitlesforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal3',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    14 => [
                        'name' => [
                            'translationKey' => 'Referrers_Type',
                        ],
                        'category' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                        ],
                        'action' => 'getReferrerType',
                        'order' => '1',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                            ],
                            'action' => 'getReferrerType',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '3',
                        ],
                        'uniqueId' => 'widgetReferrersgetReferrerTypeforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal3',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    15 => [
                        'name' => [
                            'translationKey' => 'Marketplace_PluginKeywords',
                        ],
                        'category' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                        ],
                        'action' => 'getKeywords',
                        'order' => '2',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                            ],
                            'action' => 'getKeywords',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '3',
                        ],
                        'uniqueId' => 'widgetReferrersgetKeywordsforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal3',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    16 => [
                        'name' => [
                            'translationKey' => 'Referrers_SearchEngines',
                        ],
                        'category' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                        ],
                        'action' => 'getSearchEngines',
                        'order' => '3',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                            ],
                            'action' => 'getSearchEngines',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '3',
                        ],
                        'uniqueId' => 'widgetReferrersgetSearchEnginesforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal3',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    17 => [
                        'name' => [
                            'translationKey' => 'CorePluginsAdmin_Websites',
                        ],
                        'category' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                        ],
                        'action' => 'getWebsites',
                        'order' => '4',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                            ],
                            'action' => 'getWebsites',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '3',
                        ],
                        'uniqueId' => 'widgetReferrersgetWebsitesforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal3',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    18 => [
                        'name' => [
                            'translationKey' => 'Referrers_Socials',
                        ],
                        'category' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                        ],
                        'action' => 'getSocials',
                        'order' => '5',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                            ],
                            'action' => 'getSocials',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '3',
                        ],
                        'uniqueId' => 'widgetReferrersgetSocialsforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal3',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    19 => [
                        'name' => [
                            'translationKey' => 'General_AIAssistants',
                        ],
                        'category' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                        ],
                        'action' => 'getAIAssistants',
                        'order' => '6',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                            ],
                            'action' => 'getAIAssistants',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '3',
                        ],
                        'uniqueId' => 'widgetReferrersgetAIAssistantsforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal3',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    20 => [
                        'name' => [
                            'translationKey' => 'Referrers_Campaigns',
                        ],
                        'category' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                        ],
                        'action' => 'getCampaigns',
                        'order' => '7',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                            ],
                            'action' => 'getCampaigns',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '3',
                        ],
                        'uniqueId' => 'widgetReferrersgetCampaignsforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal3',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    21 => [
                        'name' => [
                            'translationKey' => 'Goals_VisitsUntilConv',
                        ],
                        'category' => [
                            'id' => 'Goals engagement',
                            'name' => 'Goals engagement',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals engagement',
                            'name' => 'Goals engagement',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_Goals',
                        ],
                        'action' => 'getVisitsUntilConversion',
                        'order' => '201',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'table',
                            'module' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'action' => 'getVisitsUntilConversion',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '3',
                        ],
                        'uniqueId' => 'widgetGoalsgetVisitsUntilConversionforceView1viewDataTabletabledocumentationForGoalsPage1idGoal3',
                        'isWide' => '0',
                        'viewDataTable' => 'table',
                        'isReport' => '1',
                    ],
                    22 => [
                        'name' => [
                            'translationKey' => 'Goals_DaysToConv',
                        ],
                        'category' => [
                            'id' => 'Goals engagement',
                            'name' => 'Goals engagement',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals engagement',
                            'name' => 'Goals engagement',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_Goals',
                        ],
                        'action' => 'getDaysToConversion',
                        'order' => '202',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'table',
                            'module' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'action' => 'getDaysToConversion',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '3',
                        ],
                        'uniqueId' => 'widgetGoalsgetDaysToConversionforceView1viewDataTabletabledocumentationForGoalsPage1idGoal3',
                        'isWide' => '0',
                        'viewDataTable' => 'table',
                        'isReport' => '1',
                    ],
                ],
            ],
        ],
    ],
    39 => [
        'uniqueId' => 'Goals_Goals.General_Overview',
        'category' => [
            'id' => 'Goals_Goals',
            'name' => [
                'translationKey' => 'Goals_Goals',
            ],
            'order' => '25',
            'icon' => 'icon-reporting-goal',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'General_Overview',
            'name' => [
                'translationKey' => 'General_Overview',
            ],
            'order' => '2',
            'help' => '<p>The Goals Overview reports on the performance of the goals defined for your website. You can access your goal’s conversion percentages, amount of revenue generated and full reports for each.</p><p>Click on an individual metric within the sparkline chart to focus on it within the full-sized evolution graph.</p><p><a target="_blank" rel="noreferrer noopener" href="https://matomo.org/docs/tracking-goals-web-analytics/?mtm_campaign=Matomo_App&mtm_source=Matomo_App_OnPremise&mtm_medium=App.Goals.Overview">Learn more in our Goals guide here.</a></p>',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'General_Overview',
                ],
                'module' => 'CoreHome',
                'action' => 'renderWidgetContainer',
                'order' => '0',
                'parameters' => [
                    'module' => 'CoreHome',
                    'action' => 'renderWidgetContainer',
                    'containerId' => 'GoalsOverview',
                ],
                'uniqueId' => 'widgetGoalsOverview',
                'isWide' => '0',
                'layout' => '',
                'isContainer' => '1',
                'widgets' => [
                    0 => [
                        'name' => '',
                        'category' => [
                            'id' => 'Goals_Goals',
                            'name' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'General_Overview',
                            'name' => [
                                'translationKey' => 'General_Overview',
                            ],
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_Goals',
                        ],
                        'action' => 'getEvolutionGraph',
                        'order' => '5',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'graphEvolution',
                            'module' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'action' => 'getEvolutionGraph',
                        ],
                        'uniqueId' => 'widgetGoalsgetEvolutionGraphforceView1viewDataTablegraphEvolution',
                        'isWide' => '0',
                        'viewDataTable' => 'graphEvolution',
                        'isReport' => '1',
                    ],
                    1 => [
                        'name' => '',
                        'category' => [
                            'id' => 'Goals_Goals',
                            'name' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'General_Overview',
                            'name' => [
                                'translationKey' => 'General_Overview',
                            ],
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_Goals',
                        ],
                        'action' => 'getMetrics',
                        'order' => '15',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'sparklines',
                            'module' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'action' => 'getMetrics',
                        ],
                        'uniqueId' => 'widgetGoalsgetMetricsforceView1viewDataTablesparklines',
                        'isWide' => '0',
                        'viewDataTable' => 'sparklines',
                        'isReport' => '1',
                    ],
                    2 => [
                        'name' => '',
                        'category' => [
                            'id' => 'Goals_Goals',
                            'name' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'General_Overview',
                            'name' => [
                                'translationKey' => 'General_Overview',
                            ],
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_Goals',
                        ],
                        'action' => 'getSparklines',
                        'order' => '25',
                        'parameters' => [
                            'module' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'action' => 'getSparklines',
                        ],
                        'uniqueId' => 'widgetGoalsgetSparklines',
                        'isWide' => '0',
                        'viewDataTable' => '',
                        'isReport' => '1',
                    ],
                ],
            ],
            1 => [
                'name' => [
                    'translationKey' => 'Goals_ConversionsOverviewBy',
                ],
                'module' => 'CoreHome',
                'action' => 'renderWidgetContainer',
                'order' => '35',
                'parameters' => [
                    'module' => 'CoreHome',
                    'action' => 'renderWidgetContainer',
                    'containerId' => [
                        'translationKey' => 'Goals_Goals',
                    ],
                ],
                'uniqueId' => 'widgetGoals',
                'isWide' => '0',
                'middlewareParameters' => [
                    'module' => [
                        'translationKey' => 'Goals_Goals',
                    ],
                    'action' => 'hasConversions',
                ],
                'layout' => 'ByDimension',
                'isContainer' => '1',
                'widgets' => [
                    0 => [
                        'name' => [
                            'translationKey' => 'UserCountry_Country',
                        ],
                        'category' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'UserCountry',
                        'action' => 'getCountry',
                        'order' => '301',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'UserCountry',
                            'action' => 'getCountry',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '0',
                        ],
                        'uniqueId' => 'widgetUserCountrygetCountryforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal0',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    1 => [
                        'name' => [
                            'translationKey' => 'UserCountry_Continent',
                        ],
                        'category' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'UserCountry',
                        'action' => 'getContinent',
                        'order' => '302',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'UserCountry',
                            'action' => 'getContinent',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '0',
                        ],
                        'uniqueId' => 'widgetUserCountrygetContinentforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal0',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    2 => [
                        'name' => [
                            'translationKey' => 'UserCountry_Region',
                        ],
                        'category' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'UserCountry',
                        'action' => 'getRegion',
                        'order' => '303',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'UserCountry',
                            'action' => 'getRegion',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '0',
                        ],
                        'uniqueId' => 'widgetUserCountrygetRegionforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal0',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    3 => [
                        'name' => [
                            'translationKey' => 'UserCountry_City',
                        ],
                        'category' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'UserCountry',
                        'action' => 'getCity',
                        'order' => '304',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'UserCountry',
                            'action' => 'getCity',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '0',
                        ],
                        'uniqueId' => 'widgetUserCountrygetCityforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal0',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    4 => [
                        'name' => [
                            'translationKey' => 'DevicesDetection_DeviceType',
                        ],
                        'category' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'DevicesDetection',
                        'action' => 'getType',
                        'order' => '305',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'DevicesDetection',
                            'action' => 'getType',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '0',
                        ],
                        'uniqueId' => 'widgetDevicesDetectiongetTypeforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal0',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    5 => [
                        'name' => [
                            'translationKey' => 'DevicesDetection_DeviceModel',
                        ],
                        'category' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'DevicesDetection',
                        'action' => 'getModel',
                        'order' => '306',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'DevicesDetection',
                            'action' => 'getModel',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '0',
                        ],
                        'uniqueId' => 'widgetDevicesDetectiongetModelforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal0',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    6 => [
                        'name' => [
                            'translationKey' => 'DevicesDetection_DeviceBrand',
                        ],
                        'category' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'DevicesDetection',
                        'action' => 'getBrand',
                        'order' => '307',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'DevicesDetection',
                            'action' => 'getBrand',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '0',
                        ],
                        'uniqueId' => 'widgetDevicesDetectiongetBrandforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal0',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    7 => [
                        'name' => [
                            'translationKey' => 'DevicesDetection_Browsers',
                        ],
                        'category' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User location',
                            'name' => 'Goals by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'DevicesDetection',
                        'action' => 'getBrowsers',
                        'order' => '308',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'DevicesDetection',
                            'action' => 'getBrowsers',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '0',
                        ],
                        'uniqueId' => 'widgetDevicesDetectiongetBrowsersforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal0',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    8 => [
                        'name' => [
                            'translationKey' => 'VisitTime_SiteTime',
                        ],
                        'category' => [
                            'id' => 'Goals by User attribute',
                            'name' => 'Goals by User attribute',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User attribute',
                            'name' => 'Goals by User attribute',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'VisitTime',
                        'action' => 'getVisitInformationPerServerTime',
                        'order' => '401',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'VisitTime',
                            'action' => 'getVisitInformationPerServerTime',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '0',
                        ],
                        'uniqueId' => 'widgetVisitTimegetVisitInformationPerServerTimeforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal0',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    9 => [
                        'name' => 'Custom Variables',
                        'category' => [
                            'id' => 'Goals by User attribute',
                            'name' => 'Goals by User attribute',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by User attribute',
                            'name' => 'Goals by User attribute',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'CustomVariables',
                        'action' => 'getCustomVariables',
                        'order' => '402',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'CustomVariables',
                            'action' => 'getCustomVariables',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '0',
                        ],
                        'uniqueId' => 'widgetCustomVariablesgetCustomVariablesforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal0',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    10 => [
                        'name' => [
                            'translationKey' => 'Actions_PageUrls',
                        ],
                        'category' => [
                            'id' => 'Goals by Pages',
                            'name' => 'Goals by Pages',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Pages',
                            'name' => 'Goals by Pages',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'General_Actions',
                        ],
                        'action' => 'getPageUrls',
                        'order' => '101',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'General_Actions',
                            ],
                            'action' => 'getPageUrls',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '0',
                        ],
                        'uniqueId' => 'widgetActionsgetPageUrlsforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal0',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    11 => [
                        'name' => [
                            'translationKey' => 'Actions_SubmenuPagesEntry',
                        ],
                        'category' => [
                            'id' => 'Goals by Pages',
                            'name' => 'Goals by Pages',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Pages',
                            'name' => 'Goals by Pages',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'General_Actions',
                        ],
                        'action' => 'getEntryPageUrls',
                        'order' => '102',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'General_Actions',
                            ],
                            'action' => 'getEntryPageUrls',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '0',
                        ],
                        'uniqueId' => 'widgetActionsgetEntryPageUrlsforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal0',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    12 => [
                        'name' => [
                            'translationKey' => 'Actions_EntryPageTitles',
                        ],
                        'category' => [
                            'id' => 'Goals by Pages',
                            'name' => 'Goals by Pages',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Pages',
                            'name' => 'Goals by Pages',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'General_Actions',
                        ],
                        'action' => 'getEntryPageTitles',
                        'order' => '103',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'General_Actions',
                            ],
                            'action' => 'getEntryPageTitles',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '0',
                        ],
                        'uniqueId' => 'widgetActionsgetEntryPageTitlesforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal0',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    13 => [
                        'name' => [
                            'translationKey' => 'Actions_SubmenuPageTitles',
                        ],
                        'category' => [
                            'id' => 'Goals by Pages',
                            'name' => 'Goals by Pages',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Pages',
                            'name' => 'Goals by Pages',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'General_Actions',
                        ],
                        'action' => 'getPageTitles',
                        'order' => '104',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'General_Actions',
                            ],
                            'action' => 'getPageTitles',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '0',
                        ],
                        'uniqueId' => 'widgetActionsgetPageTitlesforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal0',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    14 => [
                        'name' => [
                            'translationKey' => 'Referrers_Type',
                        ],
                        'category' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                        ],
                        'action' => 'getReferrerType',
                        'order' => '1',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                            ],
                            'action' => 'getReferrerType',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '0',
                        ],
                        'uniqueId' => 'widgetReferrersgetReferrerTypeforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal0',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    15 => [
                        'name' => [
                            'translationKey' => 'Marketplace_PluginKeywords',
                        ],
                        'category' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                        ],
                        'action' => 'getKeywords',
                        'order' => '2',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                            ],
                            'action' => 'getKeywords',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '0',
                        ],
                        'uniqueId' => 'widgetReferrersgetKeywordsforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal0',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    16 => [
                        'name' => [
                            'translationKey' => 'Referrers_SearchEngines',
                        ],
                        'category' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                        ],
                        'action' => 'getSearchEngines',
                        'order' => '3',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                            ],
                            'action' => 'getSearchEngines',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '0',
                        ],
                        'uniqueId' => 'widgetReferrersgetSearchEnginesforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal0',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    17 => [
                        'name' => [
                            'translationKey' => 'CorePluginsAdmin_Websites',
                        ],
                        'category' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                        ],
                        'action' => 'getWebsites',
                        'order' => '4',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                            ],
                            'action' => 'getWebsites',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '0',
                        ],
                        'uniqueId' => 'widgetReferrersgetWebsitesforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal0',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    18 => [
                        'name' => [
                            'translationKey' => 'Referrers_Socials',
                        ],
                        'category' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                        ],
                        'action' => 'getSocials',
                        'order' => '5',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                            ],
                            'action' => 'getSocials',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '0',
                        ],
                        'uniqueId' => 'widgetReferrersgetSocialsforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal0',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    19 => [
                        'name' => [
                            'translationKey' => 'General_AIAssistants',
                        ],
                        'category' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                        ],
                        'action' => 'getAIAssistants',
                        'order' => '6',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                            ],
                            'action' => 'getAIAssistants',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '0',
                        ],
                        'uniqueId' => 'widgetReferrersgetAIAssistantsforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal0',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    20 => [
                        'name' => [
                            'translationKey' => 'Referrers_Campaigns',
                        ],
                        'category' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals by Referrers',
                            'name' => 'Goals by Referrers',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                        ],
                        'action' => 'getCampaigns',
                        'order' => '7',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                            ],
                            'action' => 'getCampaigns',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '0',
                        ],
                        'uniqueId' => 'widgetReferrersgetCampaignsforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoal0',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    21 => [
                        'name' => [
                            'translationKey' => 'Goals_VisitsUntilConv',
                        ],
                        'category' => [
                            'id' => 'Goals engagement',
                            'name' => 'Goals engagement',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals engagement',
                            'name' => 'Goals engagement',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_Goals',
                        ],
                        'action' => 'getVisitsUntilConversion',
                        'order' => '201',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'table',
                            'module' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'action' => 'getVisitsUntilConversion',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '0',
                        ],
                        'uniqueId' => 'widgetGoalsgetVisitsUntilConversionforceView1viewDataTabletabledocumentationForGoalsPage1idGoal0',
                        'isWide' => '0',
                        'viewDataTable' => 'table',
                        'isReport' => '1',
                    ],
                    22 => [
                        'name' => [
                            'translationKey' => 'Goals_DaysToConv',
                        ],
                        'category' => [
                            'id' => 'Goals engagement',
                            'name' => 'Goals engagement',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals engagement',
                            'name' => 'Goals engagement',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_Goals',
                        ],
                        'action' => 'getDaysToConversion',
                        'order' => '202',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'table',
                            'module' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'action' => 'getDaysToConversion',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => '0',
                        ],
                        'uniqueId' => 'widgetGoalsgetDaysToConversionforceView1viewDataTabletabledocumentationForGoalsPage1idGoal0',
                        'isWide' => '0',
                        'viewDataTable' => 'table',
                        'isReport' => '1',
                    ],
                ],
            ],
        ],
    ],
    40 => [
        'uniqueId' => 'Goals_Goals.Goals_ManageGoals',
        'category' => [
            'id' => 'Goals_Goals',
            'name' => [
                'translationKey' => 'Goals_Goals',
            ],
            'order' => '25',
            'icon' => 'icon-reporting-goal',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'Goals_ManageGoals',
            'name' => [
                'translationKey' => 'Goals_ManageGoals',
            ],
            'order' => '800',
            'help' => '<p>This section allows you to create and edit Goals for specific actions which visitors take on your site, such as visiting a certain page or submitting a specific form. Goal reports vary but can help you track your website performance against business objectives such as lead generation, online sales and increased brand exposure.</p><p><a target="_blank" rel="noreferrer noopener" href="https://matomo.org/docs/tracking-goals-web-analytics/">Learn more in our Goals guide here.</a></p>',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'Goals_ManageGoals',
                ],
                'module' => [
                    'translationKey' => 'Goals_Goals',
                ],
                'action' => 'editGoals',
                'order' => '99',
                'parameters' => [
                    'module' => [
                        'translationKey' => 'Goals_Goals',
                    ],
                    'action' => 'editGoals',
                ],
                'uniqueId' => 'widgetGoalseditGoals',
                'isWide' => '0',
            ],
        ],
    ],
    41 => [
        'uniqueId' => 'Goals_Ecommerce.Goals_EcommerceLog',
        'category' => [
            'id' => 'Goals_Ecommerce',
            'name' => [
                'translationKey' => 'Goals_Ecommerce',
            ],
            'order' => '20',
            'icon' => 'icon-reporting-ecommerce',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'Goals_EcommerceLog',
            'name' => [
                'translationKey' => 'Goals_EcommerceLog',
            ],
            'order' => '5',
            'help' => '<p>The Ecommerce log provides granular session-level data so you can look at the full session for each user that either made a purchase or abandoned their cart. This can help you understand what users do before and after purchasing to reveal optimisation opportunities.</p><p>Data on this page is updated in real-time.</p>',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'Goals_EcommerceLog',
                ],
                'module' => [
                    'translationKey' => 'Goals_Ecommerce',
                ],
                'action' => 'getEcommerceLog',
                'order' => '99',
                'parameters' => [
                    'module' => [
                        'translationKey' => 'Goals_Ecommerce',
                    ],
                    'action' => 'getEcommerceLog',
                ],
                'uniqueId' => 'widgetEcommercegetEcommerceLog',
                'isWide' => '0',
            ],
        ],
    ],
    42 => [
        'uniqueId' => 'Goals_Ecommerce.General_Overview',
        'category' => [
            'id' => 'Goals_Ecommerce',
            'name' => [
                'translationKey' => 'Goals_Ecommerce',
            ],
            'order' => '20',
            'icon' => 'icon-reporting-ecommerce',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'General_Overview',
            'name' => [
                'translationKey' => 'General_Overview',
            ],
            'order' => '2',
            'help' => '<p>The Ecommerce Overview section is the best place to get a high-level view of your online store’s performance. At a glance, you can see how many sales you’re making, how much revenue you are generating, and your website’s conversion rate.</p><p>Click on an individual metric within the sparkline chart to focus on it within the full-sized evolution graph.</p><p><a target="_blank" rel="noreferrer noopener" href="https://matomo.org/docs/ecommerce-analytics/?mtm_campaign=Matomo_App&mtm_source=Matomo_App_OnPremise&mtm_medium=App.Ecommerce.Overview">Learn more in our Ecommerce guide here.</a></p>',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'Goals_EcommerceOverview',
                ],
                'module' => 'CoreHome',
                'action' => 'renderWidgetContainer',
                'order' => '1',
                'parameters' => [
                    'module' => 'CoreHome',
                    'action' => 'renderWidgetContainer',
                    'containerId' => 'EcommerceOverview',
                ],
                'uniqueId' => 'widgetEcommerceOverview',
                'isWide' => '0',
                'layout' => '',
                'isContainer' => '1',
                'widgets' => [
                    0 => [
                        'name' => '',
                        'category' => [
                            'id' => 'Goals_Ecommerce',
                            'name' => [
                                'translationKey' => 'Goals_Ecommerce',
                            ],
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'General_Overview',
                            'name' => [
                                'translationKey' => 'General_Overview',
                            ],
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_Goals',
                        ],
                        'action' => 'getEvolutionGraph',
                        'order' => '5',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'graphEvolution',
                            'module' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'action' => 'getEvolutionGraph',
                            'idGoal' => 'ecommerceOrder',
                        ],
                        'uniqueId' => 'widgetGoalsgetEvolutionGraphforceView1viewDataTablegraphEvolutionidGoalecommerceOrder',
                        'isWide' => '0',
                        'viewDataTable' => 'graphEvolution',
                        'isReport' => '1',
                    ],
                    1 => [
                        'name' => '',
                        'category' => [
                            'id' => 'Goals_Ecommerce',
                            'name' => [
                                'translationKey' => 'Goals_Ecommerce',
                            ],
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'General_Overview',
                            'name' => [
                                'translationKey' => 'General_Overview',
                            ],
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_Ecommerce',
                        ],
                        'action' => 'getSparklines',
                        'order' => '15',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'sparklines',
                            'module' => [
                                'translationKey' => 'Goals_Ecommerce',
                            ],
                            'action' => 'getSparklines',
                            'idGoal' => 'ecommerceOrder',
                        ],
                        'uniqueId' => 'widgetEcommercegetSparklinesforceView1viewDataTablesparklinesidGoalecommerceOrder',
                        'isWide' => '0',
                        'viewDataTable' => 'sparklines',
                        'isReport' => '1',
                    ],
                    2 => [
                        'name' => [
                            'translationKey' => 'Goals_ConversionsOverview',
                        ],
                        'category' => [
                            'id' => 'Goals_Goals',
                            'name' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'ecommerceOrder',
                            'name' => 'ecommerceOrder',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_Ecommerce',
                        ],
                        'action' => 'getConversionsOverview',
                        'order' => '25',
                        'parameters' => [
                            'module' => [
                                'translationKey' => 'Goals_Ecommerce',
                            ],
                            'action' => 'getConversionsOverview',
                            'idGoal' => 'ecommerceOrder',
                        ],
                        'uniqueId' => 'widgetEcommercegetConversionsOverviewidGoalecommerceOrder',
                        'isWide' => '0',
                        'middlewareParameters' => [
                            'module' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'action' => 'hasConversions',
                            'idGoal' => 'ecommerceOrder',
                        ],
                        'viewDataTable' => 'table',
                        'isReport' => '1',
                    ],
                ],
            ],
        ],
    ],
    43 => [
        'uniqueId' => 'Goals_Ecommerce.Goals_Products',
        'category' => [
            'id' => 'Goals_Ecommerce',
            'name' => [
                'translationKey' => 'Goals_Ecommerce',
            ],
            'order' => '20',
            'icon' => 'icon-reporting-ecommerce',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'Goals_Products',
            'name' => [
                'translationKey' => 'Goals_Products',
            ],
            'order' => '10',
            'help' => '<p>The Products view can help you identify products and categories that are over-performing or under-performing to reveal trends and opportunities related to your product selection and store pages.</p>',
        ],
        'widgets' => [
            0 => [
                'name' => '',
                'module' => 'CoreHome',
                'action' => 'renderWidgetContainer',
                'order' => '99',
                'parameters' => [
                    'module' => 'CoreHome',
                    'action' => 'renderWidgetContainer',
                    'containerId' => [
                        'translationKey' => 'Goals_Products',
                    ],
                ],
                'uniqueId' => 'widgetProducts',
                'isWide' => '0',
                'layout' => 'ByDimension',
                'isContainer' => '1',
                'widgets' => [
                    0 => [
                        'name' => [
                            'translationKey' => 'Goals_ProductName',
                        ],
                        'category' => [
                            'id' => 'Goals_Ecommerce',
                            'name' => [
                                'translationKey' => 'Goals_Ecommerce',
                            ],
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals_Products',
                            'name' => [
                                'translationKey' => 'Goals_Products',
                            ],
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_Goals',
                        ],
                        'action' => 'getItemsName',
                        'order' => '130',
                        'parameters' => [
                            'module' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'action' => 'getItemsName',
                        ],
                        'uniqueId' => 'widgetGoalsgetItemsName',
                        'isWide' => '0',
                        'viewDataTable' => 'table',
                        'isReport' => '1',
                    ],
                    1 => [
                        'name' => [
                            'translationKey' => 'Goals_ProductSKU',
                        ],
                        'category' => [
                            'id' => 'Goals_Ecommerce',
                            'name' => [
                                'translationKey' => 'Goals_Ecommerce',
                            ],
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals_Products',
                            'name' => [
                                'translationKey' => 'Goals_Products',
                            ],
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_Goals',
                        ],
                        'action' => 'getItemsSku',
                        'order' => '131',
                        'parameters' => [
                            'module' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'action' => 'getItemsSku',
                        ],
                        'uniqueId' => 'widgetGoalsgetItemsSku',
                        'isWide' => '0',
                        'viewDataTable' => 'table',
                        'isReport' => '1',
                    ],
                    2 => [
                        'name' => [
                            'translationKey' => 'Goals_ProductCategory',
                        ],
                        'category' => [
                            'id' => 'Goals_Ecommerce',
                            'name' => [
                                'translationKey' => 'Goals_Ecommerce',
                            ],
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Goals_Products',
                            'name' => [
                                'translationKey' => 'Goals_Products',
                            ],
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_Goals',
                        ],
                        'action' => 'getItemsCategory',
                        'order' => '132',
                        'parameters' => [
                            'module' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'action' => 'getItemsCategory',
                        ],
                        'uniqueId' => 'widgetGoalsgetItemsCategory',
                        'isWide' => '0',
                        'viewDataTable' => 'table',
                        'isReport' => '1',
                    ],
                ],
            ],
        ],
    ],
    44 => [
        'uniqueId' => 'Goals_Ecommerce.Ecommerce_Sales',
        'category' => [
            'id' => 'Goals_Ecommerce',
            'name' => [
                'translationKey' => 'Goals_Ecommerce',
            ],
            'order' => '20',
            'icon' => 'icon-reporting-ecommerce',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'Ecommerce_Sales',
            'name' => [
                'translationKey' => 'Ecommerce_Sales',
            ],
            'order' => '15',
            'help' => '<p>This section contains an extensive collection of reports to help you analyse the different conditions that most commonly lead to sales, such as the traffic and campaign sources, user time and location and devices used to access them.</p><p>You can also learn exactly how revenue is associated with each dimension, such as specific traffic types or tracked campaigns.</p>',
        ],
        'widgets' => [
            0 => [
                'name' => '',
                'module' => 'CoreHome',
                'action' => 'renderWidgetContainer',
                'order' => '5',
                'parameters' => [
                    'module' => 'CoreHome',
                    'action' => 'renderWidgetContainer',
                    'idGoal' => 'ecommerceOrder',
                    'containerId' => 'GoalsOrder',
                ],
                'uniqueId' => 'widgetGoalsOrderidGoalecommerceOrder',
                'isWide' => '0',
                'layout' => 'ByDimension',
                'isContainer' => '1',
                'widgets' => [
                    0 => [
                        'name' => [
                            'translationKey' => 'UserCountry_Country',
                        ],
                        'category' => [
                            'id' => 'Sales by User location',
                            'name' => 'Sales by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Sales by User location',
                            'name' => 'Sales by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'UserCountry',
                        'action' => 'getCountry',
                        'order' => '301',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'UserCountry',
                            'action' => 'getCountry',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => 'ecommerceOrder',
                            'segmented_visitor_log_segment_suffix' => 'visitEcommerceStatus==ordered',
                        ],
                        'uniqueId' => 'widgetUserCountrygetCountryforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoalecommerceOrdersegmented_visitor_log_segment_suffixvisitEcommerceStatus3D3Dordered',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    1 => [
                        'name' => [
                            'translationKey' => 'UserCountry_Continent',
                        ],
                        'category' => [
                            'id' => 'Sales by User location',
                            'name' => 'Sales by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Sales by User location',
                            'name' => 'Sales by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'UserCountry',
                        'action' => 'getContinent',
                        'order' => '302',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'UserCountry',
                            'action' => 'getContinent',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => 'ecommerceOrder',
                            'segmented_visitor_log_segment_suffix' => 'visitEcommerceStatus==ordered',
                        ],
                        'uniqueId' => 'widgetUserCountrygetContinentforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoalecommerceOrdersegmented_visitor_log_segment_suffixvisitEcommerceStatus3D3Dordered',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    2 => [
                        'name' => [
                            'translationKey' => 'UserCountry_Region',
                        ],
                        'category' => [
                            'id' => 'Sales by User location',
                            'name' => 'Sales by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Sales by User location',
                            'name' => 'Sales by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'UserCountry',
                        'action' => 'getRegion',
                        'order' => '303',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'UserCountry',
                            'action' => 'getRegion',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => 'ecommerceOrder',
                            'segmented_visitor_log_segment_suffix' => 'visitEcommerceStatus==ordered',
                        ],
                        'uniqueId' => 'widgetUserCountrygetRegionforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoalecommerceOrdersegmented_visitor_log_segment_suffixvisitEcommerceStatus3D3Dordered',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    3 => [
                        'name' => [
                            'translationKey' => 'UserCountry_City',
                        ],
                        'category' => [
                            'id' => 'Sales by User location',
                            'name' => 'Sales by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Sales by User location',
                            'name' => 'Sales by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'UserCountry',
                        'action' => 'getCity',
                        'order' => '304',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'UserCountry',
                            'action' => 'getCity',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => 'ecommerceOrder',
                            'segmented_visitor_log_segment_suffix' => 'visitEcommerceStatus==ordered',
                        ],
                        'uniqueId' => 'widgetUserCountrygetCityforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoalecommerceOrdersegmented_visitor_log_segment_suffixvisitEcommerceStatus3D3Dordered',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    4 => [
                        'name' => [
                            'translationKey' => 'DevicesDetection_DeviceType',
                        ],
                        'category' => [
                            'id' => 'Sales by User location',
                            'name' => 'Sales by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Sales by User location',
                            'name' => 'Sales by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'DevicesDetection',
                        'action' => 'getType',
                        'order' => '305',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'DevicesDetection',
                            'action' => 'getType',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => 'ecommerceOrder',
                            'segmented_visitor_log_segment_suffix' => 'visitEcommerceStatus==ordered',
                        ],
                        'uniqueId' => 'widgetDevicesDetectiongetTypeforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoalecommerceOrdersegmented_visitor_log_segment_suffixvisitEcommerceStatus3D3Dordered',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    5 => [
                        'name' => [
                            'translationKey' => 'DevicesDetection_DeviceModel',
                        ],
                        'category' => [
                            'id' => 'Sales by User location',
                            'name' => 'Sales by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Sales by User location',
                            'name' => 'Sales by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'DevicesDetection',
                        'action' => 'getModel',
                        'order' => '306',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'DevicesDetection',
                            'action' => 'getModel',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => 'ecommerceOrder',
                            'segmented_visitor_log_segment_suffix' => 'visitEcommerceStatus==ordered',
                        ],
                        'uniqueId' => 'widgetDevicesDetectiongetModelforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoalecommerceOrdersegmented_visitor_log_segment_suffixvisitEcommerceStatus3D3Dordered',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    6 => [
                        'name' => [
                            'translationKey' => 'DevicesDetection_DeviceBrand',
                        ],
                        'category' => [
                            'id' => 'Sales by User location',
                            'name' => 'Sales by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Sales by User location',
                            'name' => 'Sales by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'DevicesDetection',
                        'action' => 'getBrand',
                        'order' => '307',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'DevicesDetection',
                            'action' => 'getBrand',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => 'ecommerceOrder',
                            'segmented_visitor_log_segment_suffix' => 'visitEcommerceStatus==ordered',
                        ],
                        'uniqueId' => 'widgetDevicesDetectiongetBrandforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoalecommerceOrdersegmented_visitor_log_segment_suffixvisitEcommerceStatus3D3Dordered',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    7 => [
                        'name' => [
                            'translationKey' => 'DevicesDetection_Browsers',
                        ],
                        'category' => [
                            'id' => 'Sales by User location',
                            'name' => 'Sales by User location',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Sales by User location',
                            'name' => 'Sales by User location',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'DevicesDetection',
                        'action' => 'getBrowsers',
                        'order' => '308',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'DevicesDetection',
                            'action' => 'getBrowsers',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => 'ecommerceOrder',
                            'segmented_visitor_log_segment_suffix' => 'visitEcommerceStatus==ordered',
                        ],
                        'uniqueId' => 'widgetDevicesDetectiongetBrowsersforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoalecommerceOrdersegmented_visitor_log_segment_suffixvisitEcommerceStatus3D3Dordered',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    8 => [
                        'name' => [
                            'translationKey' => 'VisitTime_SiteTime',
                        ],
                        'category' => [
                            'id' => 'Sales by User attribute',
                            'name' => 'Sales by User attribute',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Sales by User attribute',
                            'name' => 'Sales by User attribute',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'VisitTime',
                        'action' => 'getVisitInformationPerServerTime',
                        'order' => '401',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'VisitTime',
                            'action' => 'getVisitInformationPerServerTime',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => 'ecommerceOrder',
                            'segmented_visitor_log_segment_suffix' => 'visitEcommerceStatus==ordered',
                        ],
                        'uniqueId' => 'widgetVisitTimegetVisitInformationPerServerTimeforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoalecommerceOrdersegmented_visitor_log_segment_suffixvisitEcommerceStatus3D3Dordered',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    9 => [
                        'name' => 'Custom Variables',
                        'category' => [
                            'id' => 'Sales by User attribute',
                            'name' => 'Sales by User attribute',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Sales by User attribute',
                            'name' => 'Sales by User attribute',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => 'CustomVariables',
                        'action' => 'getCustomVariables',
                        'order' => '402',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => 'CustomVariables',
                            'action' => 'getCustomVariables',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => 'ecommerceOrder',
                            'segmented_visitor_log_segment_suffix' => 'visitEcommerceStatus==ordered',
                        ],
                        'uniqueId' => 'widgetCustomVariablesgetCustomVariablesforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoalecommerceOrdersegmented_visitor_log_segment_suffixvisitEcommerceStatus3D3Dordered',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    10 => [
                        'name' => [
                            'translationKey' => 'Actions_PageUrls',
                        ],
                        'category' => [
                            'id' => 'Sales by Pages',
                            'name' => 'Sales by Pages',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Sales by Pages',
                            'name' => 'Sales by Pages',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'General_Actions',
                        ],
                        'action' => 'getPageUrls',
                        'order' => '101',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'General_Actions',
                            ],
                            'action' => 'getPageUrls',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => 'ecommerceOrder',
                            'segmented_visitor_log_segment_suffix' => 'visitEcommerceStatus==ordered',
                        ],
                        'uniqueId' => 'widgetActionsgetPageUrlsforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoalecommerceOrdersegmented_visitor_log_segment_suffixvisitEcommerceStatus3D3Dordered',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    11 => [
                        'name' => [
                            'translationKey' => 'Actions_SubmenuPagesEntry',
                        ],
                        'category' => [
                            'id' => 'Sales by Pages',
                            'name' => 'Sales by Pages',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Sales by Pages',
                            'name' => 'Sales by Pages',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'General_Actions',
                        ],
                        'action' => 'getEntryPageUrls',
                        'order' => '102',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'General_Actions',
                            ],
                            'action' => 'getEntryPageUrls',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => 'ecommerceOrder',
                            'segmented_visitor_log_segment_suffix' => 'visitEcommerceStatus==ordered',
                        ],
                        'uniqueId' => 'widgetActionsgetEntryPageUrlsforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoalecommerceOrdersegmented_visitor_log_segment_suffixvisitEcommerceStatus3D3Dordered',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    12 => [
                        'name' => [
                            'translationKey' => 'Actions_EntryPageTitles',
                        ],
                        'category' => [
                            'id' => 'Sales by Pages',
                            'name' => 'Sales by Pages',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Sales by Pages',
                            'name' => 'Sales by Pages',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'General_Actions',
                        ],
                        'action' => 'getEntryPageTitles',
                        'order' => '103',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'General_Actions',
                            ],
                            'action' => 'getEntryPageTitles',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => 'ecommerceOrder',
                            'segmented_visitor_log_segment_suffix' => 'visitEcommerceStatus==ordered',
                        ],
                        'uniqueId' => 'widgetActionsgetEntryPageTitlesforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoalecommerceOrdersegmented_visitor_log_segment_suffixvisitEcommerceStatus3D3Dordered',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    13 => [
                        'name' => [
                            'translationKey' => 'Actions_SubmenuPageTitles',
                        ],
                        'category' => [
                            'id' => 'Sales by Pages',
                            'name' => 'Sales by Pages',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Sales by Pages',
                            'name' => 'Sales by Pages',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'General_Actions',
                        ],
                        'action' => 'getPageTitles',
                        'order' => '104',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'General_Actions',
                            ],
                            'action' => 'getPageTitles',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => 'ecommerceOrder',
                            'segmented_visitor_log_segment_suffix' => 'visitEcommerceStatus==ordered',
                        ],
                        'uniqueId' => 'widgetActionsgetPageTitlesforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoalecommerceOrdersegmented_visitor_log_segment_suffixvisitEcommerceStatus3D3Dordered',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    14 => [
                        'name' => [
                            'translationKey' => 'Referrers_Type',
                        ],
                        'category' => [
                            'id' => 'Sales by Referrers',
                            'name' => 'Sales by Referrers',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Sales by Referrers',
                            'name' => 'Sales by Referrers',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                        ],
                        'action' => 'getReferrerType',
                        'order' => '1',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                            ],
                            'action' => 'getReferrerType',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => 'ecommerceOrder',
                            'segmented_visitor_log_segment_suffix' => 'visitEcommerceStatus==ordered',
                        ],
                        'uniqueId' => 'widgetReferrersgetReferrerTypeforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoalecommerceOrdersegmented_visitor_log_segment_suffixvisitEcommerceStatus3D3Dordered',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    15 => [
                        'name' => [
                            'translationKey' => 'Marketplace_PluginKeywords',
                        ],
                        'category' => [
                            'id' => 'Sales by Referrers',
                            'name' => 'Sales by Referrers',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Sales by Referrers',
                            'name' => 'Sales by Referrers',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                        ],
                        'action' => 'getKeywords',
                        'order' => '2',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                            ],
                            'action' => 'getKeywords',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => 'ecommerceOrder',
                            'segmented_visitor_log_segment_suffix' => 'visitEcommerceStatus==ordered',
                        ],
                        'uniqueId' => 'widgetReferrersgetKeywordsforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoalecommerceOrdersegmented_visitor_log_segment_suffixvisitEcommerceStatus3D3Dordered',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    16 => [
                        'name' => [
                            'translationKey' => 'Referrers_SearchEngines',
                        ],
                        'category' => [
                            'id' => 'Sales by Referrers',
                            'name' => 'Sales by Referrers',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Sales by Referrers',
                            'name' => 'Sales by Referrers',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                        ],
                        'action' => 'getSearchEngines',
                        'order' => '3',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                            ],
                            'action' => 'getSearchEngines',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => 'ecommerceOrder',
                            'segmented_visitor_log_segment_suffix' => 'visitEcommerceStatus==ordered',
                        ],
                        'uniqueId' => 'widgetReferrersgetSearchEnginesforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoalecommerceOrdersegmented_visitor_log_segment_suffixvisitEcommerceStatus3D3Dordered',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    17 => [
                        'name' => [
                            'translationKey' => 'CorePluginsAdmin_Websites',
                        ],
                        'category' => [
                            'id' => 'Sales by Referrers',
                            'name' => 'Sales by Referrers',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Sales by Referrers',
                            'name' => 'Sales by Referrers',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                        ],
                        'action' => 'getWebsites',
                        'order' => '4',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                            ],
                            'action' => 'getWebsites',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => 'ecommerceOrder',
                            'segmented_visitor_log_segment_suffix' => 'visitEcommerceStatus==ordered',
                        ],
                        'uniqueId' => 'widgetReferrersgetWebsitesforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoalecommerceOrdersegmented_visitor_log_segment_suffixvisitEcommerceStatus3D3Dordered',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    18 => [
                        'name' => [
                            'translationKey' => 'Referrers_Socials',
                        ],
                        'category' => [
                            'id' => 'Sales by Referrers',
                            'name' => 'Sales by Referrers',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Sales by Referrers',
                            'name' => 'Sales by Referrers',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                        ],
                        'action' => 'getSocials',
                        'order' => '5',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                            ],
                            'action' => 'getSocials',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => 'ecommerceOrder',
                            'segmented_visitor_log_segment_suffix' => 'visitEcommerceStatus==ordered',
                        ],
                        'uniqueId' => 'widgetReferrersgetSocialsforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoalecommerceOrdersegmented_visitor_log_segment_suffixvisitEcommerceStatus3D3Dordered',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    19 => [
                        'name' => [
                            'translationKey' => 'General_AIAssistants',
                        ],
                        'category' => [
                            'id' => 'Sales by Referrers',
                            'name' => 'Sales by Referrers',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Sales by Referrers',
                            'name' => 'Sales by Referrers',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                        ],
                        'action' => 'getAIAssistants',
                        'order' => '6',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                            ],
                            'action' => 'getAIAssistants',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => 'ecommerceOrder',
                            'segmented_visitor_log_segment_suffix' => 'visitEcommerceStatus==ordered',
                        ],
                        'uniqueId' => 'widgetReferrersgetAIAssistantsforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoalecommerceOrdersegmented_visitor_log_segment_suffixvisitEcommerceStatus3D3Dordered',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    20 => [
                        'name' => [
                            'translationKey' => 'Referrers_Campaigns',
                        ],
                        'category' => [
                            'id' => 'Sales by Referrers',
                            'name' => 'Sales by Referrers',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Sales by Referrers',
                            'name' => 'Sales by Referrers',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                        ],
                        'action' => 'getCampaigns',
                        'order' => '7',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'tableGoals',
                            'module' => [
                                'translationKey' => 'Goals_CategoryTextReferrers_Referrers',
                            ],
                            'action' => 'getCampaigns',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => 'ecommerceOrder',
                            'segmented_visitor_log_segment_suffix' => 'visitEcommerceStatus==ordered',
                        ],
                        'uniqueId' => 'widgetReferrersgetCampaignsforceView1viewDataTabletableGoalsdocumentationForGoalsPage1idGoalecommerceOrdersegmented_visitor_log_segment_suffixvisitEcommerceStatus3D3Dordered',
                        'isWide' => '0',
                        'viewDataTable' => 'tableGoals',
                        'isReport' => '1',
                    ],
                    21 => [
                        'name' => [
                            'translationKey' => 'Goals_VisitsUntilConv',
                        ],
                        'category' => [
                            'id' => 'Sales engagement',
                            'name' => 'Sales engagement',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Sales engagement',
                            'name' => 'Sales engagement',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_Goals',
                        ],
                        'action' => 'getVisitsUntilConversion',
                        'order' => '201',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'table',
                            'module' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'action' => 'getVisitsUntilConversion',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => 'ecommerceOrder',
                            'segmented_visitor_log_segment_suffix' => 'visitEcommerceStatus==ordered',
                        ],
                        'uniqueId' => 'widgetGoalsgetVisitsUntilConversionforceView1viewDataTabletabledocumentationForGoalsPage1idGoalecommerceOrdersegmented_visitor_log_segment_suffixvisitEcommerceStatus3D3Dordered',
                        'isWide' => '0',
                        'viewDataTable' => 'table',
                        'isReport' => '1',
                    ],
                    22 => [
                        'name' => [
                            'translationKey' => 'Goals_DaysToConv',
                        ],
                        'category' => [
                            'id' => 'Sales engagement',
                            'name' => 'Sales engagement',
                            'order' => '99',
                            'icon' => '',
                            'help' => '',
                            'widget' => '',
                            'groups' => [
                                0 => '',
                            ],
                        ],
                        'subcategory' => [
                            'id' => 'Sales engagement',
                            'name' => 'Sales engagement',
                            'order' => '99',
                            'help' => '',
                        ],
                        'module' => [
                            'translationKey' => 'Goals_Goals',
                        ],
                        'action' => 'getDaysToConversion',
                        'order' => '202',
                        'parameters' => [
                            'forceView' => '1',
                            'viewDataTable' => 'table',
                            'module' => [
                                'translationKey' => 'Goals_Goals',
                            ],
                            'action' => 'getDaysToConversion',
                            'documentationForGoalsPage' => '1',
                            'idGoal' => 'ecommerceOrder',
                            'segmented_visitor_log_segment_suffix' => 'visitEcommerceStatus==ordered',
                        ],
                        'uniqueId' => 'widgetGoalsgetDaysToConversionforceView1viewDataTabletabledocumentationForGoalsPage1idGoalecommerceOrdersegmented_visitor_log_segment_suffixvisitEcommerceStatus3D3Dordered',
                        'isWide' => '0',
                        'viewDataTable' => 'table',
                        'isReport' => '1',
                    ],
                ],
            ],
        ],
    ],
    45 => [
        'uniqueId' => 'Marketplace_Marketplace.Marketplace_Browse',
        'category' => [
            'id' => 'Marketplace_Marketplace',
            'name' => [
                'translationKey' => 'Marketplace_Marketplace',
            ],
            'order' => '200',
            'icon' => '',
            'help' => '',
            'widget' => 'Marketplace.RichMenuButton',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'Marketplace_Browse',
            'name' => [
                'translationKey' => 'Marketplace_Browse',
            ],
            'order' => '5',
            'help' => '',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'Marketplace_Marketplace',
                ],
                'module' => [
                    'translationKey' => 'Marketplace_Marketplace',
                ],
                'action' => 'overview',
                'order' => '19',
                'parameters' => [
                    'module' => [
                        'translationKey' => 'Marketplace_Marketplace',
                    ],
                    'action' => 'overview',
                    'embed' => '1',
                ],
                'uniqueId' => 'widgetMarketplaceoverviewembed1',
                'isWide' => '0',
            ],
        ],
    ],
    46 => [
        'uniqueId' => 'Marketplace_Marketplace.Marketplace_PaidPlugins',
        'category' => [
            'id' => 'Marketplace_Marketplace',
            'name' => [
                'translationKey' => 'Marketplace_Marketplace',
            ],
            'order' => '200',
            'icon' => '',
            'help' => '',
            'widget' => 'Marketplace.RichMenuButton',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'Marketplace_PaidPlugins',
            'name' => [
                'translationKey' => 'Marketplace_PaidPlugins',
            ],
            'order' => '99',
            'help' => '',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'Marketplace_PaidPlugins',
                ],
                'module' => [
                    'translationKey' => 'Marketplace_Marketplace',
                ],
                'action' => 'getPremiumFeatures',
                'order' => '20',
                'parameters' => [
                    'module' => [
                        'translationKey' => 'Marketplace_Marketplace',
                    ],
                    'action' => 'getPremiumFeatures',
                ],
                'uniqueId' => 'widgetMarketplacegetPremiumFeatures',
                'isWide' => '0',
            ],
        ],
    ],
    47 => [
        'uniqueId' => 'ProfessionalServices_PromoAbTesting.ProfessionalServices_PromoOverview',
        'category' => [
            'id' => 'ProfessionalServices_PromoAbTesting',
            'name' => [
                'translationKey' => 'ProfessionalServices_PromoAbTesting',
            ],
            'order' => '51',
            'icon' => 'icon-lab',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'ProfessionalServices_PromoOverview',
            'name' => [
                'translationKey' => 'General_Overview',
            ],
            'order' => '99',
            'help' => '',
        ],
        'widgets' => [
            0 => [
                'name' => '',
                'module' => 'ProfessionalServices',
                'action' => 'promoAbTesting',
                'order' => '99',
                'parameters' => [
                    'module' => 'ProfessionalServices',
                    'action' => 'promoAbTesting',
                ],
                'uniqueId' => 'widgetProfessionalServicespromoAbTesting',
                'isWide' => '0',
            ],
        ],
    ],
    48 => [
        'uniqueId' => 'ProfessionalServices_PromoCrashAnalytics.ProfessionalServices_PromoOverview',
        'category' => [
            'id' => 'ProfessionalServices_PromoCrashAnalytics',
            'name' => [
                'translationKey' => 'ProfessionalServices_PromoCrashAnalytics',
            ],
            'order' => '70',
            'icon' => 'icon-bug',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'ProfessionalServices_PromoOverview',
            'name' => [
                'translationKey' => 'General_Overview',
            ],
            'order' => '99',
            'help' => '',
        ],
        'widgets' => [
            0 => [
                'name' => '',
                'module' => 'ProfessionalServices',
                'action' => 'promoCrashAnalytics',
                'order' => '99',
                'parameters' => [
                    'module' => 'ProfessionalServices',
                    'action' => 'promoCrashAnalytics',
                ],
                'uniqueId' => 'widgetProfessionalServicespromoCrashAnalytics',
                'isWide' => '0',
            ],
        ],
    ],
    49 => [
        'uniqueId' => 'ProfessionalServices_PromoCustomReports.ProfessionalServices_PromoManage',
        'category' => [
            'id' => 'ProfessionalServices_PromoCustomReports',
            'name' => [
                'translationKey' => 'ProfessionalServices_PromoCustomReports',
            ],
            'order' => '65',
            'icon' => 'icon-business',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'ProfessionalServices_PromoManage',
            'name' => [
                'translationKey' => 'ProfessionalServices_PromoManage',
            ],
            'order' => '99',
            'help' => '',
        ],
        'widgets' => [
            0 => [
                'name' => '',
                'module' => 'ProfessionalServices',
                'action' => 'promoCustomReports',
                'order' => '99',
                'parameters' => [
                    'module' => 'ProfessionalServices',
                    'action' => 'promoCustomReports',
                ],
                'uniqueId' => 'widgetProfessionalServicespromoCustomReports',
                'isWide' => '0',
            ],
        ],
    ],
    50 => [
        'uniqueId' => 'ProfessionalServices_PromoFormAnalytics.ProfessionalServices_PromoOverview',
        'category' => [
            'id' => 'ProfessionalServices_PromoFormAnalytics',
            'name' => [
                'translationKey' => 'ProfessionalServices_PromoFormAnalytics',
            ],
            'order' => '49',
            'icon' => 'icon-form',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'ProfessionalServices_PromoOverview',
            'name' => [
                'translationKey' => 'General_Overview',
            ],
            'order' => '99',
            'help' => '',
        ],
        'widgets' => [
            0 => [
                'name' => '',
                'module' => 'ProfessionalServices',
                'action' => 'promoFormAnalytics',
                'order' => '99',
                'parameters' => [
                    'module' => 'ProfessionalServices',
                    'action' => 'promoFormAnalytics',
                ],
                'uniqueId' => 'widgetProfessionalServicespromoFormAnalytics',
                'isWide' => '0',
            ],
        ],
    ],
    51 => [
        'uniqueId' => 'ProfessionalServices_PromoFunnels.ProfessionalServices_PromoOverview',
        'category' => [
            'id' => 'ProfessionalServices_PromoFunnels',
            'name' => [
                'translationKey' => 'ProfessionalServices_PromoFunnels',
            ],
            'order' => '28',
            'icon' => 'icon-funnel',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'ProfessionalServices_PromoOverview',
            'name' => [
                'translationKey' => 'General_Overview',
            ],
            'order' => '99',
            'help' => '',
        ],
        'widgets' => [
            0 => [
                'name' => '',
                'module' => 'ProfessionalServices',
                'action' => 'promoFunnels',
                'order' => '99',
                'parameters' => [
                    'module' => 'ProfessionalServices',
                    'action' => 'promoFunnels',
                ],
                'uniqueId' => 'widgetProfessionalServicespromoFunnels',
                'isWide' => '0',
            ],
        ],
    ],
    52 => [
        'uniqueId' => 'ProfessionalServices_PromoHeatmaps.ProfessionalServices_PromoManage',
        'category' => [
            'id' => 'ProfessionalServices_PromoHeatmaps',
            'name' => [
                'translationKey' => 'ProfessionalServices_PromoHeatmaps',
            ],
            'order' => '58',
            'icon' => 'icon-drop',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'ProfessionalServices_PromoManage',
            'name' => [
                'translationKey' => 'ProfessionalServices_PromoManage',
            ],
            'order' => '99',
            'help' => '',
        ],
        'widgets' => [
            0 => [
                'name' => '',
                'module' => 'ProfessionalServices',
                'action' => 'promoHeatmaps',
                'order' => '99',
                'parameters' => [
                    'module' => 'ProfessionalServices',
                    'action' => 'promoHeatmaps',
                ],
                'uniqueId' => 'widgetProfessionalServicespromoHeatmaps',
                'isWide' => '0',
            ],
        ],
    ],
    53 => [
        'uniqueId' => 'ProfessionalServices_PromoMediaAnalytics.ProfessionalServices_PromoOverview',
        'category' => [
            'id' => 'ProfessionalServices_PromoMediaAnalytics',
            'name' => [
                'translationKey' => 'ProfessionalServices_PromoMediaAnalytics',
            ],
            'order' => '50',
            'icon' => 'icon-folder-charts',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'ProfessionalServices_PromoOverview',
            'name' => [
                'translationKey' => 'General_Overview',
            ],
            'order' => '99',
            'help' => '',
        ],
        'widgets' => [
            0 => [
                'name' => '',
                'module' => 'ProfessionalServices',
                'action' => 'promoMediaAnalytics',
                'order' => '99',
                'parameters' => [
                    'module' => 'ProfessionalServices',
                    'action' => 'promoMediaAnalytics',
                ],
                'uniqueId' => 'widgetProfessionalServicespromoMediaAnalytics',
                'isWide' => '0',
            ],
        ],
    ],
    54 => [
        'uniqueId' => 'ProfessionalServices_PromoSessionRecording.ProfessionalServices_PromoManage',
        'category' => [
            'id' => 'ProfessionalServices_PromoSessionRecording',
            'name' => [
                'translationKey' => 'ProfessionalServices_PromoSessionRecording',
            ],
            'order' => '59',
            'icon' => 'icon-play',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'ProfessionalServices_PromoManage',
            'name' => [
                'translationKey' => 'ProfessionalServices_PromoManage',
            ],
            'order' => '99',
            'help' => '',
        ],
        'widgets' => [
            0 => [
                'name' => '',
                'module' => 'ProfessionalServices',
                'action' => 'promoSessionRecordings',
                'order' => '99',
                'parameters' => [
                    'module' => 'ProfessionalServices',
                    'action' => 'promoSessionRecordings',
                ],
                'uniqueId' => 'widgetProfessionalServicespromoSessionRecordings',
                'isWide' => '0',
            ],
        ],
    ],
    55 => [
        'uniqueId' => 'ExampleUI_UiFramework.ExampleUI_GetTemperaturesDataTable',
        'category' => [
            'id' => 'ExampleUI_UiFramework',
            'name' => [
                'translationKey' => 'ExampleUI_UiFramework',
            ],
            'order' => '90',
            'icon' => '',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'ExampleUI_GetTemperaturesDataTable',
            'name' => [
                'translationKey' => 'ExampleUI_GetTemperaturesDataTable',
            ],
            'order' => '99',
            'help' => '',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'ExampleUI_GetTemperaturesDataTable',
                ],
                'module' => 'ExampleUI',
                'action' => 'getTemperatures',
                'order' => '210',
                'parameters' => [
                    'module' => 'ExampleUI',
                    'action' => 'getTemperatures',
                ],
                'uniqueId' => 'widgetExampleUIgetTemperatures',
                'isWide' => '0',
                'viewDataTable' => 'table',
                'isReport' => '1',
            ],
        ],
    ],
    56 => [
        'uniqueId' => 'ExampleUI_UiFramework.Bar graph',
        'category' => [
            'id' => 'ExampleUI_UiFramework',
            'name' => [
                'translationKey' => 'ExampleUI_UiFramework',
            ],
            'order' => '90',
            'icon' => '',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'Bar graph',
            'name' => 'Bar graph',
            'order' => '99',
            'help' => '',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'ExampleUI_GetTemperaturesDataTable',
                ],
                'module' => 'ExampleUI',
                'action' => 'getTemperatures',
                'order' => '210',
                'parameters' => [
                    'forceView' => '1',
                    'viewDataTable' => 'graphVerticalBar',
                    'module' => 'ExampleUI',
                    'action' => 'getTemperatures',
                ],
                'uniqueId' => 'widgetExampleUIgetTemperaturesforceView1viewDataTablegraphVerticalBar',
                'isWide' => '0',
                'viewDataTable' => 'graphVerticalBar',
                'isReport' => '1',
            ],
        ],
    ],
    57 => [
        'uniqueId' => 'ExampleUI_UiFramework.Treemap',
        'category' => [
            'id' => 'ExampleUI_UiFramework',
            'name' => [
                'translationKey' => 'ExampleUI_UiFramework',
            ],
            'order' => '90',
            'icon' => '',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'Treemap',
            'name' => 'Treemap',
            'order' => '99',
            'help' => '',
        ],
        'widgets' => [
            0 => [
                'name' => 'Treemap example',
                'module' => 'ExampleUI',
                'action' => 'getTemperatures',
                'order' => '210',
                'parameters' => [
                    'forceView' => '1',
                    'viewDataTable' => 'infoviz-treemap',
                    'module' => 'ExampleUI',
                    'action' => 'getTemperatures',
                ],
                'uniqueId' => 'widgetExampleUIgetTemperaturesforceView1viewDataTableinfoviz-treemap',
                'isWide' => '0',
                'viewDataTable' => 'infoviz-treemap',
                'isReport' => '1',
            ],
        ],
    ],
    58 => [
        'uniqueId' => 'ExampleUI_UiFramework.Sparklines',
        'category' => [
            'id' => 'ExampleUI_UiFramework',
            'name' => [
                'translationKey' => 'ExampleUI_UiFramework',
            ],
            'order' => '90',
            'icon' => '',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'Sparklines',
            'name' => 'Sparklines',
            'order' => '99',
            'help' => '',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'ExampleUI_GetTemperaturesEvolution',
                ],
                'module' => 'ExampleUI',
                'action' => 'getTemperaturesEvolution',
                'order' => '211',
                'parameters' => [
                    'forceView' => '1',
                    'viewDataTable' => 'sparklines',
                    'module' => 'ExampleUI',
                    'action' => 'getTemperaturesEvolution',
                ],
                'uniqueId' => 'widgetExampleUIgetTemperaturesEvolutionforceView1viewDataTablesparklines',
                'isWide' => '0',
                'viewDataTable' => 'sparklines',
                'isReport' => '1',
            ],
        ],
    ],
    59 => [
        'uniqueId' => 'ExampleUI_UiFramework.Evolution Graph',
        'category' => [
            'id' => 'ExampleUI_UiFramework',
            'name' => [
                'translationKey' => 'ExampleUI_UiFramework',
            ],
            'order' => '90',
            'icon' => '',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'Evolution Graph',
            'name' => 'Evolution Graph',
            'order' => '99',
            'help' => '',
        ],
        'widgets' => [
            0 => [
                'name' => [
                    'translationKey' => 'ExampleUI_TemperaturesEvolution',
                ],
                'module' => 'ExampleUI',
                'action' => 'getTemperaturesEvolution',
                'order' => '211',
                'parameters' => [
                    'forceView' => '1',
                    'viewDataTable' => 'graphEvolution',
                    'module' => 'ExampleUI',
                    'action' => 'getTemperaturesEvolution',
                    'columns' => [
                        0 => 'server1',
                        1 => 'server2',
                    ],
                ],
                'uniqueId' => 'widgetExampleUIgetTemperaturesEvolutionforceView1viewDataTablegraphEvolutioncolumnsArray',
                'isWide' => '0',
                'viewDataTable' => 'graphEvolution',
                'isReport' => '1',
            ],
        ],
    ],
    60 => [
        'uniqueId' => 'ExampleUI_UiFramework.Pie graph',
        'category' => [
            'id' => 'ExampleUI_UiFramework',
            'name' => [
                'translationKey' => 'ExampleUI_UiFramework',
            ],
            'order' => '90',
            'icon' => '',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'Pie graph',
            'name' => 'Pie graph',
            'order' => '99',
            'help' => '',
        ],
        'widgets' => [
            0 => [
                'name' => 'Pie graph',
                'module' => 'ExampleUI',
                'action' => 'getPlanetRatios',
                'order' => '212',
                'parameters' => [
                    'module' => 'ExampleUI',
                    'action' => 'getPlanetRatios',
                ],
                'uniqueId' => 'widgetExampleUIgetPlanetRatios',
                'isWide' => '0',
                'viewDataTable' => 'graphPie',
                'isReport' => '1',
            ],
        ],
    ],
    61 => [
        'uniqueId' => 'ExampleUI_UiFramework.Tag clouds',
        'category' => [
            'id' => 'ExampleUI_UiFramework',
            'name' => [
                'translationKey' => 'ExampleUI_UiFramework',
            ],
            'order' => '90',
            'icon' => '',
            'help' => '',
            'widget' => '',
            'groups' => [
                0 => '',
            ],
        ],
        'subcategory' => [
            'id' => 'Tag clouds',
            'name' => 'Tag clouds',
            'order' => '99',
            'help' => '',
        ],
        'widgets' => [
            0 => [
                'name' => 'Simple tag cloud',
                'module' => 'ExampleUI',
                'action' => 'getPlanetRatios',
                'order' => '5',
                'parameters' => [
                    'forceView' => '1',
                    'viewDataTable' => 'cloud',
                    'module' => 'ExampleUI',
                    'action' => 'getPlanetRatios',
                ],
                'uniqueId' => 'widgetExampleUIgetPlanetRatiosforceView1viewDataTablecloud',
                'isWide' => '0',
                'viewDataTable' => 'cloud',
                'isReport' => '1',
            ],
            1 => [
                'name' => 'Advanced tag cloud: with logos and links',
                'module' => 'ExampleUI',
                'action' => 'getPlanetRatiosWithLogos',
                'order' => '213',
                'parameters' => [
                    'module' => 'ExampleUI',
                    'action' => 'getPlanetRatiosWithLogos',
                ],
                'uniqueId' => 'widgetExampleUIgetPlanetRatiosWithLogos',
                'isWide' => '0',
                'viewDataTable' => 'cloud',
                'isReport' => '1',
            ],
        ],
    ],
];
