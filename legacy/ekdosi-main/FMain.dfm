object FrmMain: TFrmMain
  Left = 0
  Top = 0
  Caption = #904#954#948#959#963#951
  ClientHeight = 707
  ClientWidth = 1112
  Color = clBtnFace
  Font.Charset = DEFAULT_CHARSET
  Font.Color = clWindowText
  Font.Height = -11
  Font.Name = 'Tahoma'
  Font.Style = []
  FormStyle = fsMDIForm
  Menu = MainMenu
  OldCreateOrder = False
  WindowMenu = WindowMenu
  OnClose = FormClose
  OnCloseQuery = FormCloseQuery
  OnCreate = FormCreate
  OnResize = FormResize
  OnShow = FormShow
  PixelsPerInch = 96
  TextHeight = 13
  object lblAddress: TJvLabel
    Left = 426
    Top = 86
    Width = 7
    Height = 16
    Caption = '-'
    Font.Charset = DEFAULT_CHARSET
    Font.Color = clWindowText
    Font.Height = -13
    Font.Name = 'Tahoma'
    Font.Style = []
    ParentFont = False
    RoundedFrame = 1
    Transparent = True
    Visible = False
  end
  object lblCaptionAddress: TJvLabel
    Left = 328
    Top = 86
    Width = 92
    Height = 16
    Alignment = taRightJustify
    AutoSize = False
    Caption = #916#953#949#965#952#965#957#963#951':'
    Font.Charset = DEFAULT_CHARSET
    Font.Color = clWindowText
    Font.Height = -13
    Font.Name = 'Tahoma'
    Font.Style = []
    ParentFont = False
    RoundedFrame = 1
    Transparent = True
    Visible = False
  end
  object lblCaptionName: TJvLabel
    Left = 328
    Top = 64
    Width = 92
    Height = 16
    Alignment = taRightJustify
    AutoSize = False
    Caption = #917#960#969#957#965#956#943#945':'
    Font.Charset = DEFAULT_CHARSET
    Font.Color = clWindowText
    Font.Height = -13
    Font.Name = 'Tahoma'
    Font.Style = []
    ParentFont = False
    RoundedFrame = 1
    Transparent = True
    Visible = False
  end
  object lblCaptionPhone: TJvLabel
    Left = 328
    Top = 108
    Width = 92
    Height = 16
    Alignment = taRightJustify
    AutoSize = False
    Caption = #932#951#955#949#966#969#957#959':'
    Font.Charset = DEFAULT_CHARSET
    Font.Color = clWindowText
    Font.Height = -13
    Font.Name = 'Tahoma'
    Font.Style = []
    ParentFont = False
    RoundedFrame = 1
    Transparent = True
    Visible = False
  end
  object lblName: TJvLabel
    Left = 426
    Top = 64
    Width = 7
    Height = 16
    Caption = '-'
    Font.Charset = DEFAULT_CHARSET
    Font.Color = clWindowText
    Font.Height = -13
    Font.Name = 'Tahoma'
    Font.Style = []
    ParentFont = False
    RoundedFrame = 1
    Transparent = True
    Visible = False
  end
  object lblTelephone: TJvLabel
    Left = 426
    Top = 108
    Width = 7
    Height = 16
    Caption = '-'
    Font.Charset = DEFAULT_CHARSET
    Font.Color = clWindowText
    Font.Height = -13
    Font.Name = 'Tahoma'
    Font.Style = []
    ParentFont = False
    RoundedFrame = 1
    Transparent = True
    Visible = False
  end
  object PanelCustOrder: TJvPanel
    Left = 0
    Top = 0
    Width = 305
    Height = 688
    Align = alLeft
    Caption = 'PanelCustOrder'
    TabOrder = 1
    Visible = False
    OnResize = PanelCustOrderResize
    object PanelComponents: TJvPanel
      Left = 1
      Top = 1
      Width = 303
      Height = 686
      FlatBorder = True
      Align = alClient
      TabOrder = 0
      object PanelSearch: TJvPanel
        Left = 1
        Top = 1
        Width = 301
        Height = 35
        FlatBorder = True
        Align = alTop
        BevelOuter = bvNone
        TabOrder = 0
        DesignSize = (
          301
          35)
        object LabelEx: TJvLabel
          Left = 276
          Top = 6
          Width = 15
          Height = 23
          Caption = 'X'
          Font.Charset = DEFAULT_CHARSET
          Font.Color = clWindowText
          Font.Height = -19
          Font.Name = 'Tahoma'
          Font.Style = [fsBold]
          Anchors = [akTop, akRight]
          ParentFont = False
          RoundedFrame = 1
          Transparent = True
          OnClick = LabelExClick
          OnMouseEnter = LabelExMouseEnter
          OnMouseLeave = LabelExMouseLeave
          HotTrackFont.Charset = DEFAULT_CHARSET
          HotTrackFont.Color = clWindowText
          HotTrackFont.Height = -19
          HotTrackFont.Name = 'Tahoma'
          HotTrackFont.Style = []
        end
        object editSearch: TJvDotNetEdit
          Left = 6
          Top = 8
          Width = 267
          Height = 22
          Anchors = [akLeft, akTop, akRight]
          Font.Charset = DEFAULT_CHARSET
          Font.Color = clWindowText
          Font.Height = -12
          Font.Name = 'Tahoma'
          Font.Style = []
          ParentFont = False
          TabOrder = 0
          Text = ''
          OnChange = editSearchChange
        end
      end
      object GridMilestones: TcxGrid
        Left = 1
        Top = 36
        Width = 301
        Height = 649
        Align = alClient
        BevelInner = bvNone
        BevelOuter = bvNone
        BorderStyle = cxcbsNone
        TabOrder = 1
        DragOpening = False
        LookAndFeel.Kind = lfStandard
        LookAndFeel.NativeStyle = True
        object ViewCustOrder: TcxGridDBTableView
          OnDblClick = ViewCustOrderDblClick
          Navigator.Buttons.CustomButtons = <>
          Navigator.Buttons.First.Visible = False
          Navigator.Buttons.PriorPage.Hint = #928#961#959#951#947#959#973#956#949#957#951' '#963#949#955#943#948#945
          Navigator.Buttons.Prior.Enabled = False
          Navigator.Buttons.Prior.Visible = False
          Navigator.Buttons.Next.Enabled = False
          Navigator.Buttons.Next.Visible = False
          Navigator.Buttons.NextPage.Hint = #917#960#972#956#949#957#951' '#963#949#955#943#948#945
          Navigator.Buttons.Last.Visible = False
          Navigator.Buttons.Insert.Visible = False
          Navigator.Buttons.Append.Enabled = False
          Navigator.Buttons.Append.Visible = False
          Navigator.Buttons.Delete.Enabled = False
          Navigator.Buttons.Delete.Visible = False
          Navigator.Buttons.Edit.Enabled = False
          Navigator.Buttons.Edit.Visible = False
          Navigator.Buttons.Post.Visible = False
          Navigator.Buttons.Cancel.Enabled = False
          Navigator.Buttons.Cancel.Visible = False
          Navigator.Buttons.Refresh.Visible = False
          Navigator.Buttons.Filter.Hint = #934#953#955#964#961#940#961#953#963#956#945' '#948#949#948#959#956#941#957#969#957
          DataController.DataModeController.SmartRefresh = True
          DataController.DataSource = DSCustomerOrder
          DataController.Summary.DefaultGroupSummaryItems = <>
          DataController.Summary.FooterSummaryItems = <>
          DataController.Summary.SummaryGroups = <>
          NewItemRow.InfoText = #917#953#963#945#947#969#947#942
          OptionsBehavior.NavigatorHints = True
          OptionsCustomize.ColumnFiltering = False
          OptionsCustomize.ColumnGrouping = False
          OptionsCustomize.ColumnHiding = True
          OptionsCustomize.ColumnSorting = False
          OptionsCustomize.ColumnsQuickCustomization = True
          OptionsCustomize.ColumnsQuickCustomizationReordering = qcrEnabled
          OptionsData.Appending = True
          OptionsSelection.CellSelect = False
          OptionsView.FocusRect = False
          OptionsView.NavigatorOffset = 10
          OptionsView.NoDataToDisplayInfoText = '<'#916#949#957' '#965#960#940#961#967#959#965#957' '#948#949#948#959#956#941#957#945'>'
          OptionsView.ScrollBars = ssVertical
          OptionsView.GridLines = glVertical
          OptionsView.GroupByBox = False
          OptionsView.HeaderEndEllipsis = True
          Styles.Background = StyleMain
          Styles.ContentEven = StyleEven
          Styles.ContentOdd = StyleOdd
          object ViewCustOrderCASE: TcxGridDBColumn
            Caption = #917#960#943#963#954#949#968#951
            DataBinding.FieldName = 'CASE'
            PropertiesClassName = 'TcxCheckBoxProperties'
            Properties.Alignment = taRightJustify
            Properties.ValueChecked = 1
            Properties.ValueUnchecked = 0
            Width = 30
          end
          object ViewCustOrderNAME: TcxGridDBColumn
            Caption = #917#960#969#957#965#956#943#945
            DataBinding.FieldName = 'NAME'
          end
          object ViewCustOrderCONCATENATION: TcxGridDBColumn
            Caption = #916#953#949#952#965#957#963#951
            DataBinding.FieldName = 'CONCATENATION'
            Visible = False
          end
          object ViewCustOrderPHONE: TcxGridDBColumn
            DataBinding.FieldName = 'PHONE'
            Visible = False
          end
        end
        object Level1: TcxGridLevel
          GridView = ViewCustOrder
        end
      end
    end
  end
  object StatusBar: TJvStatusBar
    Left = 0
    Top = 688
    Width = 1112
    Height = 19
    BiDiMode = bdLeftToRight
    Panels = <
      item
        BiDiMode = bdRightToLeft
        ParentBiDiMode = False
        Width = 430
      end
      item
        Alignment = taCenter
        Bevel = pbRaised
        Text = '00:00:00'
        Width = 55
      end
      item
        BiDiMode = bdLeftToRight
        ParentBiDiMode = False
        Width = 150
      end>
    ParentBiDiMode = False
  end
  object Splitter1: TJvxSplitter
    Left = 305
    Top = 0
    Width = 3
    Height = 688
    ControlFirst = PanelCustOrder
    Align = alLeft
    Visible = False
  end
  object MainMenu: TJvMainMenu
    Style = msXP
    ImageMargin.Left = 0
    ImageMargin.Top = 0
    ImageMargin.Right = 0
    ImageMargin.Bottom = 0
    ImageSize.Height = 0
    ImageSize.Width = 0
    Left = 504
    Top = 16
    object N1: TMenuItem
      Caption = #913#961#967#949#943#959
      object N6: TMenuItem
        Caption = #931#973#957#948#949#963#951
        OnClick = N6Click
      end
      object N7: TMenuItem
        Caption = #913#960#959#963#973#957#948#949#963#951
        OnClick = N7Click
      end
      object N8: TMenuItem
        Caption = '-'
      end
      object N9: TMenuItem
        Caption = #904#958#959#948#959#962
        OnClick = N9Click
      end
    end
    object N2: TMenuItem
      Caption = #928#949#955#940#964#949#962
      object mnuNewCustomer: TMenuItem
        Caption = #917#953#963#945#947#969#947#942
        OnClick = mnuNewCustomerClick
      end
      object mnuShowCustomers: TMenuItem
        Caption = #928#961#959#946#959#955#942
        OnClick = mnuShowCustomersClick
      end
      object N26: TMenuItem
        Caption = #933#960#972#955#959#953#960#945
        object N11: TMenuItem
          Caption = #928#961#959#946#959#955#942
          OnClick = N11Click
        end
        object N27: TMenuItem
          Caption = #925#941#945' '#960#955#951#961#969#956#942
          OnClick = N27Click
        end
      end
      object N32: TMenuItem
        Caption = #928#961#959#946#959#955#942' '#963#949#953#961#940#962
        object N19: TMenuItem
          Caption = #917#960#953#955#959#947#942' '#963#949#953#961#940#962' '#960#961#959#946#959#955#942#962
          OnClick = N19Click
        end
        object MenuShowCustOrder: TMenuItem
          AutoCheck = True
          Caption = #928#961#959#946#959#955#942' '#963#949#953#961#940#962
          OnClick = MenuShowCustOrderClick
        end
      end
    end
    object N13: TMenuItem
      Caption = #913#960#959#952#942#954#951
      object N21: TMenuItem
        Caption = #917#953#963#945#947#969#947#942' '#949#953#948#974#957
        OnClick = N21Click
      end
      object N22: TMenuItem
        Caption = #928#961#959#946#959#955#942' '#949#953#948#974#957
        OnClick = N22Click
      end
      object N23: TMenuItem
        Caption = #922#945#964#951#947#959#961#943#949#962' '#949#953#948#974#957
        OnClick = N23Click
      end
    end
    object N10: TMenuItem
      Caption = #928#969#955#942#963#949#953#962
      object MNewInvoice2: TMenuItem
        Caption = 'AddInvoice2'
        OnClick = MNewInvoice2Click
      end
      object MNewInvoice: TMenuItem
        Caption = #925#941#959' '#960#945#961#945#963#964#945#964#953#954#972
        OnClick = MNewInvoiceClick
      end
      object NDash: TMenuItem
        Caption = '-'
      end
    end
    object N29: TMenuItem
      Caption = #928#945#961#945#963#964#945#964#953#954#940
      object N30: TMenuItem
        Caption = #928#961#959#946#959#955#942
        OnClick = N30Click
      end
      object menuRemainingMyData: TMenuItem
        Caption = #917#954#954#961#949#956#942' '#960#945#961#945#963#964#945#964#953#954#940' '#960#961#959#962' MyDATA'
        OnClick = menuRemainingMyDataClick
      end
    end
    object N24: TMenuItem
      Caption = #916#953#940#966#959#961#945
      object N25: TMenuItem
        Caption = #931#965#947#954#949#957#964#961#969#964#953#954#972' '#916#913#928
        OnClick = N25Click
      end
      object N31: TMenuItem
        Caption = #931#965#947#954'. '#916#949#955#964'. '#949#960#953#963#964#961#959#966#942#962
        OnClick = N31Click
      end
      object CSCart1: TMenuItem
        Caption = #931#965#947#967#961#959#957#953#963#956#972#962' '#960#961#959#953#972#957#964#969#957' CS Cart'
        OnClick = CSCart1Click
      end
      object N34: TMenuItem
        Caption = #917#965#961#949#963#951' '#948#953#960#955#974#957' '#954#969#948#953#954#974#957
        OnClick = N34Click
      end
      object N33: TMenuItem
        Caption = #924#949#964#945#964#961#959#960#942' '#960#961#959#964#953#956#959#955#959#947#953#974#957
        OnClick = N33Click
      end
      object CSUsers1: TMenuItem
        Caption = 'CS Users'
        OnClick = CSUsers1Click
      end
      object CSInvoices1: TMenuItem
        Caption = 'CS Invoices'
        OnClick = CSInvoices1Click
      end
      object N36: TMenuItem
        Caption = #913#965#964#972#956#945#964#951' '#964#953#956#959#955#972#947#951#963#951
        OnClick = N36Click
      end
    end
    object N3: TMenuItem
      Caption = #928#945#961#945#956#949#964#961#959#960#959#943#951#963#951
      object N12: TMenuItem
        Caption = #922#945#964#951#947#959#961#943#949#962' '#934#928#913
        OnClick = N12Click
      end
      object MCalendar: TMenuItem
        Caption = #932#973#960#959#953' '#960#945#961#945#963#964#945#964#953#954#974#957
        OnClick = MCalendarClick
      end
      object N16: TMenuItem
        Caption = #932#961#972#960#959#953' '#960#945#961#940#948#959#963#951#962
        OnClick = N16Click
      end
      object N17: TMenuItem
        Caption = #932#961#972#960#959#953' '#960#955#951#961#969#956#942#962
        OnClick = N17Click
      end
      object N18: TMenuItem
        Caption = #931#954#959#960#972#962' '#948#953#945#954#943#957#951#963#951#962
        OnClick = N18Click
      end
      object N28: TMenuItem
        Caption = #924#959#957#940#948#949#962' '#956#941#964#961#951#963#951#962
        OnClick = N28Click
      end
    end
    object N14: TMenuItem
      Caption = #917#960#953#955#959#947#941#962
      object N15: TMenuItem
        Caption = #928#945#961#940#956#949#964#961#959#953
        OnClick = MParametersClick
      end
      object N20: TMenuItem
        Caption = #913#955#955#945#947#942' '#951#956#949#961#959#956#951#957#943#945#962
        OnClick = N20Click
      end
      object mail: TMenuItem
        Caption = 'SendMail'
      end
    end
    object N4: TMenuItem
      Caption = #913#957#945#966#959#961#941#962
      object menuReportDesign: TMenuItem
        Caption = #931#967#949#948#943#945#963#951' '#945#957#945#966#959#961#974#957
        OnClick = menuReportDesignClick
      end
    end
    object WindowMenu: TMenuItem
      AutoCheck = True
      Caption = #928#945#961#940#952#965#961#959
      object Window1: TMenuItem
        Caption = #922#955#949#943#963#953#956#959' '#972#955#969#957
      end
    end
    object myDATA1: TMenuItem
      Caption = 'myDATA'
      object N35: TMenuItem
        Caption = #923#942#968#951' '#945#960#949#963#964#945#955#956#941#957#969#957' '#960#945#961#945#963#964#945#964#953#954#974#957
        OnClick = N35Click
      end
    end
    object N5: TMenuItem
      Caption = #914#959#942#952#949#953#945
      object AboutOptimum1: TMenuItem
        Caption = #902#948#949#953#945' '#967#961#942#963#951#962' '#955#959#947#953#963#956#953#954#959#973
        OnClick = AboutOptimum1Click
      end
    end
  end
  object database: TIBDatabase
    DatabaseName = 'localhost:C:\projects\DATA\EKDOSI.FDB'
    Params.Strings = (
      'user_name=SYSDBA'
      'password=masterkey'
      'lc_ctype=WIN1253')
    LoginPrompt = False
    ServerType = 'IBServer'
    AfterConnect = databaseAfterConnect
    AfterDisconnect = databaseAfterDisconnect
    BeforeConnect = databaseBeforeConnect
    Left = 704
    Top = 16
  end
  object Timer1: TTimer
    Interval = 900
    OnTimer = Timer1Timer
    Left = 592
    Top = 16
  end
  object IBTransaction1: TIBTransaction
    DefaultDatabase = database
    Params.Strings = (
      'read_committed'
      'rec_version'
      'nowait')
    Left = 536
    Top = 16
  end
  object ActionList1: TActionList
    Left = 664
    Top = 16
    object Action1: TAction
      Caption = 'Action1'
    end
  end
  object DatasetCustomerOrder: TIBDataSet
    Database = database
    Transaction = IBTransaction1
    AutoCalcFields = False
    AfterScroll = DatasetCustomerOrderAfterScroll
    BeforeOpen = DatasetCustomerOrderBeforeOpen
    BufferChunks = 1000
    CachedUpdates = False
    DeleteSQL.Strings = (
      'delete from "PAYMENT"'
      'where'
      '  "PAYMENT"."PAYMENT_ID" = :"OLD_PAYMENT_ID"')
    InsertSQL.Strings = (
      'insert into "PAYMENT"'
      
        '  ("PAYMENT"."CUST_ID", "PAYMENT"."NOTES", "PAYMENT"."PAY_DATE",' +
        ' "PAYMENT"."PAYMENT_ID", '
      '   "PAYMENT"."VALUE")'
      'values'
      '  (:"CUST_ID", :"NOTES", :"PAY_DATE", :"PAYMENT_ID", :"VALUE")')
    RefreshSQL.Strings = (
      'Select '
      '  "PAYMENT"."PAYMENT_ID",'
      '  "PAYMENT"."CUST_ID",'
      '  "PAYMENT"."PAY_DATE",'
      '  "PAYMENT"."VALUE",'
      '  "PAYMENT"."NOTES"'
      'from "PAYMENT" '
      'where'
      '  "PAYMENT"."PAYMENT_ID" = :"PAYMENT_ID"')
    SelectSQL.Strings = (
      'SELECT D.CUST_ID, (SELECT CASE'
      '                WHEN (COUNT(*) > 0) THEN 1'
      '                WHEN (COUNT(*) = 0) THEN 0'
      '                END'
      
        '                 FROM INVOICE WHERE CUST_ID = D.CUST_ID AND INVD' +
        'ATE = :DT), NAME , '
      'COALESCE(ADDRESS1,'#39#39') || '#39' '#39'|| COALESCE(ADDRESS2,'#39#39'),'
      
        #39'T1: '#39'||COALESCE(PHONE1,'#39'N/A'#39') || '#39' /  '#932'2: '#39'|| COALESCE(PHONE2 ,' +
        #39'N/A'#39') AS PHONE'
      'FROM CUSTOMER D'
      'ORDER BY'
      ' "ORDER" ASC')
    ModifySQL.Strings = (
      'update "PAYMENT"'
      'set'
      '  "PAYMENT"."CUST_ID" = :"CUST_ID",'
      '  "PAYMENT"."NOTES" = :"NOTES",'
      '  "PAYMENT"."PAY_DATE" = :"PAY_DATE",'
      '  "PAYMENT"."PAYMENT_ID" = :"PAYMENT_ID",'
      '  "PAYMENT"."VALUE" = :"VALUE"'
      'where'
      '  "PAYMENT"."PAYMENT_ID" = :"OLD_PAYMENT_ID"')
    ParamCheck = True
    UniDirectional = False
    Left = 388
    Top = 176
    object DatasetCustomerOrderNAME: TIBStringField
      FieldName = 'NAME'
      Origin = '"CUSTOMER"."NAME"'
      ReadOnly = True
      Required = True
      Size = 50
    end
    object DatasetCustomerOrderCONCATENATION: TIBStringField
      FieldName = 'CONCATENATION'
      ProviderFlags = []
      ReadOnly = True
      Size = 101
    end
    object DatasetCustomerOrderCASE: TIntegerField
      FieldName = 'CASE'
      ProviderFlags = []
      ReadOnly = True
    end
    object DatasetCustomerOrderPHONE: TIBStringField
      FieldName = 'PHONE'
      ProviderFlags = []
      Size = 42
    end
    object DatasetCustomerOrderCUST_ID: TIntegerField
      FieldName = 'CUST_ID'
      Origin = '"CUSTOMER"."CUST_ID"'
      ProviderFlags = [pfInUpdate, pfInWhere, pfInKey]
      Required = True
    end
  end
  object DSCustomerOrder: TDataSource
    AutoEdit = False
    DataSet = DatasetCustomerOrder
    Left = 420
    Top = 176
  end
  object StyleRepo: TcxStyleRepository
    PixelsPerInch = 96
    object StyleMain: TcxStyle
      AssignedValues = [svColor, svFont, svTextColor]
      Color = clCream
      Font.Charset = DEFAULT_CHARSET
      Font.Color = clWindowText
      Font.Height = -12
      Font.Name = 'Tahoma'
      Font.Style = []
      TextColor = clGradientActiveCaption
    end
    object StyleEven: TcxStyle
      AssignedValues = [svColor]
      Color = clDefault
    end
    object StyleOdd: TcxStyle
      AssignedValues = [svColor]
      Color = clDefault
    end
    object StyleGroupBox: TcxStyle
    end
  end
end
