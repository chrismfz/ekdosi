object FrmShowCustomers: TFrmShowCustomers
  Left = 0
  Top = 0
  Caption = #928#961#959#946#959#955#942' '#960#949#955#945#964#974#957
  ClientHeight = 651
  ClientWidth = 835
  Color = clBtnFace
  ParentFont = True
  FormStyle = fsMDIChild
  GlassFrame.Enabled = True
  OldCreateOrder = False
  Visible = True
  OnCloseQuery = FormCloseQuery
  OnShow = FormShow
  PixelsPerInch = 96
  TextHeight = 13
  object PanelTop: TJvPanel
    Left = 0
    Top = 0
    Width = 835
    Height = 32
    Align = alTop
    BevelOuter = bvNone
    TabOrder = 0
    object PanelSearch: TJvPanel
      Left = 0
      Top = 0
      Width = 552
      Height = 32
      Align = alClient
      BevelOuter = bvLowered
      TabOrder = 0
      DesignSize = (
        552
        32)
      object btnAdd: TJvExpressButton
        Left = 516
        Top = 3
        Width = 33
        Height = 25
        Anchors = [akTop, akRight]
        Font.Charset = DEFAULT_CHARSET
        Font.Color = clHighlightText
        Font.Height = -11
        Font.Name = 'Tahoma'
        Font.Style = []
        HighlightFont.Charset = DEFAULT_CHARSET
        HighlightFont.Color = clWindowText
        HighlightFont.Height = -11
        HighlightFont.Name = 'Tahoma'
        HighlightFont.Style = []
        ImageIndex = 11
        ImageSize = isSmall
        ParentImageSize = False
        SmallImages = ImageList1
        OnClick = btnAddClick
        ExplicitLeft = 538
      end
    end
    object JvPanel1: TJvPanel
      Left = 552
      Top = 0
      Width = 283
      Height = 32
      FlatBorder = True
      Align = alRight
      TabOrder = 1
      object JvToolBar1: TJvToolBar
        Left = 1
        Top = 1
        Width = 281
        Height = 33
        ButtonHeight = 30
        ButtonWidth = 32
        Caption = 'JvToolBar1'
        Constraints.MinWidth = 272
        EdgeInner = esNone
        Images = ImageList1
        ParentShowHint = False
        ShowHint = False
        TabOrder = 0
        Transparent = True
        object ToolPrevious: TToolButton
          Left = 0
          Top = 0
          Hint = #928#961#959#951#947#959#973#956#949#957#959
          Caption = 'ToolPrevious'
          ImageIndex = 0
        end
        object ToolNext: TToolButton
          Left = 32
          Top = 0
          Hint = #917#960#972#956#949#957#959
          Caption = 'ToolNext'
          ImageIndex = 1
          ParentShowHint = False
          ShowHint = True
        end
        object ToolButton4: TToolButton
          Left = 64
          Top = 0
          Width = 8
          Caption = 'ToolButton4'
          ImageIndex = 3
          Style = tbsSeparator
        end
        object ToolAdd: TToolButton
          Left = 72
          Top = 0
          Hint = #917#953#963#945#947#969#947#942
          Caption = 'ToolAdd'
          ImageIndex = 2
          OnClick = ToolAddClick
        end
        object ToolDelete: TToolButton
          Left = 104
          Top = 0
          Hint = #916#953#945#947#961#945#966#942
          Caption = 'ToolDelete'
          ImageIndex = 3
          OnClick = ToolDeleteClick
        end
        object ToolButton8: TToolButton
          Left = 136
          Top = 0
          Width = 8
          Caption = 'ToolButton8'
          ImageIndex = 6
          Style = tbsSeparator
        end
        object ToolEdit: TToolButton
          Left = 144
          Top = 0
          Hint = #917#960#949#958#949#961#947#945#963#943#945
          Caption = 'ToolEdit'
          ImageIndex = 4
          OnClick = ToolEditClick
        end
        object ToolAccept: TToolButton
          Left = 176
          Top = 0
          Hint = #913#960#959#948#959#967#942
          Caption = 'ToolAccept'
          Enabled = False
          ImageIndex = 5
          OnClick = ToolAcceptClick
        end
        object ToolCancel: TToolButton
          Left = 208
          Top = 0
          Hint = #913#960#972#961#961#953#968#951
          Caption = 'ToolCancel'
          Enabled = False
          ImageIndex = 6
          OnClick = ToolCancelClick
        end
        object ToolButton2: TToolButton
          Left = 240
          Top = 0
          Width = 8
          Caption = 'ToolButton2'
          ImageIndex = 8
          Style = tbsSeparator
        end
        object ToolRefresh: TToolButton
          Left = 248
          Top = 0
          Hint = #913#957#945#957#941#969#963#951
          Caption = 'ToolRefresh'
          ImageIndex = 7
        end
      end
    end
  end
  object PanelMain: TJvPanel
    Left = 0
    Top = 32
    Width = 835
    Height = 288
    Transparent = True
    Align = alClient
    TabOrder = 1
    object GridCustomers: TcxGrid
      Left = 1
      Top = 1
      Width = 833
      Height = 286
      Align = alClient
      BorderStyle = cxcbsNone
      TabOrder = 0
      DragOpening = False
      LookAndFeel.Kind = lfStandard
      LookAndFeel.NativeStyle = True
      object ViewCustomers: TcxGridDBTableView
        Navigator.Buttons.CustomButtons = <>
        Navigator.Buttons.First.Visible = False
        Navigator.Buttons.PriorPage.Hint = #928#961#959#951#947#959#973#956#949#957#951' '#963#949#955#943#948#945
        Navigator.Buttons.Prior.Enabled = False
        Navigator.Buttons.Prior.Visible = False
        Navigator.Buttons.Next.Enabled = False
        Navigator.Buttons.Next.Visible = False
        Navigator.Buttons.NextPage.Hint = #917#960#972#956#949#957#951' '#963#949#955#943#948#945
        Navigator.Buttons.Last.Visible = False
        Navigator.Buttons.Insert.Enabled = False
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
        Navigator.Visible = True
        DataController.DataModeController.GridMode = True
        DataController.DataSource = DSCustomers
        DataController.Summary.DefaultGroupSummaryItems = <
          item
            Kind = skSum
          end>
        DataController.Summary.FooterSummaryItems = <>
        DataController.Summary.SummaryGroups = <>
        FilterRow.ApplyChanges = fracImmediately
        NewItemRow.InfoText = #917#953#963#945#947#969#947#942
        OptionsBehavior.NavigatorHints = True
        OptionsCustomize.ColumnHiding = True
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
        OptionsView.Indicator = True
        Styles.Background = StyleMain
        Styles.ContentEven = StyleEven
        Styles.ContentOdd = StyleOdd
        object ViewCustomersCUST_ID: TcxGridDBColumn
          Caption = 'ID'
          DataBinding.FieldName = 'CUST_ID'
        end
        object ViewCustomersNAME: TcxGridDBColumn
          Caption = #917#960#969#957#965#956#943#945
          DataBinding.FieldName = 'NAME'
          Width = 240
        end
        object ViewCustomersAFM: TcxGridDBColumn
          Caption = #913#934#924
          DataBinding.FieldName = 'AFM'
        end
        object ViewCustomersOCCUPATION: TcxGridDBColumn
          Caption = #916#961#945#963#964#951#961#953#972#964#951#964#945
          DataBinding.FieldName = 'OCCUPATION'
          Width = 250
        end
        object ViewCustomersCITY: TcxGridDBColumn
          Caption = #928#972#955#951
          DataBinding.FieldName = 'CITY'
          Width = 120
        end
        object ViewCustomersBALANCE: TcxGridDBColumn
          Caption = #933#960#972#955#959#953#960#959
          DataBinding.FieldName = 'BALANCE'
          Width = 80
        end
      end
      object GridCustomersLevel1: TcxGridLevel
        GridView = ViewCustomers
      end
    end
  end
  object StatusBar1: TStatusBar
    Left = 0
    Top = 632
    Width = 835
    Height = 19
    AutoHint = True
    Panels = <>
  end
  object RollDetail: TJvRollOut
    Left = 0
    Top = 320
    Width = 835
    Height = 312
    Align = alBottom
    ButtonHeight = 23
    Caption = #923#949#960#964#959#956#941#961#953#949#962
    Font.Charset = DEFAULT_CHARSET
    Font.Color = clWindowText
    Font.Height = -11
    Font.Name = 'Tahoma'
    Font.Style = []
    ImageOptions.IndexCollapsed = 11
    ImageOptions.IndexExpanded = 10
    ImageOptions.Images = ImageList1
    ParentFont = False
    ShowFocus = False
    TabOrder = 3
    ToggleAnywhere = False
    FAWidth = 145
    FAHeight = 312
    FCWidth = 919
    FCHeight = 25
    object PageControl: TJvPageControl
      Left = 1
      Top = 24
      Width = 833
      Height = 287
      ActivePage = TabSheet1
      Align = alClient
      TabOrder = 0
      OnChange = PageControlChange
      object TabSheet1: TTabSheet
        Caption = #915#949#957#953#954#940' '#963#964#959#953#967#949#943#945
        object lblCheck: TJvLabel
          Left = 234
          Top = 63
          Width = 26
          Height = 16
          Font.Charset = DEFAULT_CHARSET
          Font.Color = clWindowText
          Font.Height = -12
          Font.Name = 'Tahoma'
          Font.Style = []
          ParentFont = False
          Transparent = True
          Visible = False
          Images = ImageList2
          ImageIndex = 1
        end
        object Label12: TLabel
          Left = 281
          Top = 65
          Width = 24
          Height = 14
          Hint = #928#949#961#953#947#961#945#966#942' '#956#945#952#942#956#945#964#959#962
          Alignment = taRightJustify
          Caption = #916#927#933
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -12
          Font.Name = 'Tahoma'
          Font.Style = []
          ParentFont = False
        end
        object Label11: TLabel
          Left = 78
          Top = 65
          Width = 27
          Height = 14
          Hint = #928#949#961#953#947#961#945#966#942' '#956#945#952#942#956#945#964#959#962
          Alignment = taRightJustify
          Caption = #913#934#924
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -12
          Font.Name = 'Tahoma'
          Font.Style = []
          ParentFont = False
        end
        object Label10: TLabel
          Left = 28
          Top = 233
          Width = 76
          Height = 14
          Hint = #928#949#961#953#947#961#945#966#942' '#956#945#952#942#956#945#964#959#962
          Alignment = taRightJustify
          Caption = #904#954#960#964#969#963#951' (%)'
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -12
          Font.Name = 'Tahoma'
          Font.Style = []
          ParentFont = False
          Visible = False
        end
        object Label8: TLabel
          Left = 424
          Top = 140
          Width = 21
          Height = 14
          Hint = #928#949#961#953#947#961#945#966#942' '#956#945#952#942#956#945#964#959#962
          Alignment = taRightJustify
          Caption = 'FAX'
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -12
          Font.Name = 'Tahoma'
          Font.Style = []
          ParentFont = False
        end
        object Label7: TLabel
          Left = 577
          Top = 116
          Width = 32
          Height = 14
          Hint = #928#949#961#953#947#961#945#966#942' '#956#945#952#942#956#945#964#959#962
          Alignment = taRightJustify
          Caption = #932#951#955' 2'
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -12
          Font.Name = 'Tahoma'
          Font.Style = []
          ParentFont = False
        end
        object Label6: TLabel
          Left = 413
          Top = 116
          Width = 32
          Height = 14
          Hint = #928#949#961#953#947#961#945#966#942' '#956#945#952#942#956#945#964#959#962
          Alignment = taRightJustify
          Caption = #932#951#955' 1'
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -12
          Font.Name = 'Tahoma'
          Font.Style = []
          ParentFont = False
        end
        object Label5: TLabel
          Left = 76
          Top = 163
          Width = 28
          Height = 14
          Hint = #928#949#961#953#947#961#945#966#942' '#956#945#952#942#956#945#964#959#962
          Alignment = taRightJustify
          Caption = #928#972#955#951
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -12
          Font.Name = 'Tahoma'
          Font.Style = []
          ParentFont = False
        end
        object Label4: TLabel
          Left = 34
          Top = 186
          Width = 70
          Height = 14
          Hint = #928#949#961#953#947#961#945#966#942' '#956#945#952#942#956#945#964#959#962
          Alignment = taRightJustify
          Caption = #932#945#967'. '#954#974#948#953#954#945#962
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -12
          Font.Name = 'Tahoma'
          Font.Style = []
          ParentFont = False
        end
        object Label3: TLabel
          Left = 50
          Top = 114
          Width = 55
          Height = 14
          Hint = #928#949#961#953#947#961#945#966#942' '#956#945#952#942#956#945#964#959#962
          Alignment = taRightJustify
          Caption = #916#953#949#973#952#965#957#963#951
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -12
          Font.Name = 'Tahoma'
          Font.Style = []
          ParentFont = False
        end
        object Label2: TLabel
          Left = 22
          Top = 37
          Width = 83
          Height = 14
          Hint = #928#949#961#953#947#961#945#966#942' '#956#945#952#942#956#945#964#959#962
          Alignment = taRightJustify
          Caption = #916#961#945#963#964#951#961#953#972#964#951#964#945
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -12
          Font.Name = 'Tahoma'
          Font.Style = []
          ParentFont = False
        end
        object Label9: TLabel
          Left = 500
          Top = 6
          Width = 77
          Height = 18
          Cursor = crArrow
          Alignment = taRightJustify
          AutoSize = False
          Caption = #928#945#961#945#964#951#961#942#963#949#953#962
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -12
          Font.Name = 'Tahoma'
          Font.Style = []
          ParentFont = False
          Transparent = True
        end
        object Label1: TLabel
          Left = 32
          Top = 11
          Width = 73
          Height = 18
          Cursor = crArrow
          Alignment = taRightJustify
          AutoSize = False
          Caption = #917#960#969#957#965#956#943#945
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -12
          Font.Name = 'Tahoma'
          Font.Style = []
          ParentFont = False
          Transparent = True
        end
        object Label13: TLabel
          Left = 415
          Top = 166
          Width = 30
          Height = 14
          Hint = #928#949#961#953#947#961#945#966#942' '#956#945#952#942#956#945#964#959#962
          Alignment = taRightJustify
          Caption = 'E-Mail'
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -12
          Font.Name = 'Tahoma'
          Font.Style = []
          ParentFont = False
        end
        object Label14: TLabel
          Left = 75
          Top = 209
          Width = 29
          Height = 14
          Hint = #928#949#961#953#947#961#945#966#942' '#956#945#952#942#956#945#964#959#962
          Alignment = taRightJustify
          Caption = #935#974#961#945
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -12
          Font.Name = 'Tahoma'
          Font.Style = []
          ParentFont = False
        end
        object Label15: TLabel
          Left = 367
          Top = 214
          Width = 78
          Height = 14
          Hint = #928#949#961#953#947#961#945#966#942' '#956#945#952#942#956#945#964#959#962
          Alignment = taRightJustify
          Caption = #932#961'. '#928#955#951#961#969#956#942#962
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -12
          Font.Name = 'Tahoma'
          Font.Style = []
          ParentFont = False
        end
        object Label16: TLabel
          Left = 55
          Top = 89
          Width = 50
          Height = 14
          Hint = #928#949#961#953#947#961#945#966#942' '#956#945#952#942#956#945#964#959#962
          Alignment = taRightJustify
          Caption = 'Vies VAT'
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -12
          Font.Name = 'Tahoma'
          Font.Style = []
          ParentFont = False
        end
        object Label17: TLabel
          Left = 370
          Top = 190
          Width = 75
          Height = 14
          Hint = #928#949#961#953#947#961#945#966#942' '#956#945#952#942#956#945#964#959#962
          Alignment = taRightJustify
          Caption = #916#949#965#964#949#961'. E-Mail'
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -12
          Font.Name = 'Tahoma'
          Font.Style = []
          ParentFont = False
        end
        object DBEditName: TJvDotNetDBEdit
          Left = 111
          Top = 11
          Width = 378
          Height = 17
          Hint = 'Domain name ('#933#960#959#967#961#949#969#964#953#954#972')'
          AutoSize = False
          Color = clInfoBk
          DataField = 'NAME'
          DataSource = DSCustomers
          Font.Charset = GREEK_CHARSET
          Font.Color = clWindowText
          Font.Height = -12
          Font.Name = 'Arial'
          Font.Style = [fsBold]
          ParentFont = False
          TabOrder = 0
        end
        object cxDBLookupComboBox2: TcxDBLookupComboBox
          Left = 111
          Top = 33
          Hint = #916#961#945#963#964#951#961#953#972#964#951#964#945' '#949#960#953#967#949#943#961#951#963#951#962
          DataBinding.DataField = 'OCCUPATION'
          DataBinding.DataSource = DSCustomers
          ParentFont = False
          Properties.DropDownListStyle = lsEditList
          Properties.KeyFieldNames = 'OCCUPATION'
          Properties.ListColumns = <
            item
              FieldName = 'OCCUPATION'
            end>
          Properties.ListOptions.ShowHeader = False
          Properties.ListSource = DSOcupation
          Style.Color = clInfoBk
          Style.Font.Charset = DEFAULT_CHARSET
          Style.Font.Color = clWindowText
          Style.Font.Height = -12
          Style.Font.Name = 'Tahoma'
          Style.Font.Style = []
          Style.IsFontAssigned = True
          TabOrder = 1
          Width = 274
        end
        object cxDBLookupComboBox3: TcxDBLookupComboBox
          Left = 311
          Top = 61
          Hint = #913#961#956#972#948#953#945' '#916#927#933
          DataBinding.DataField = 'TAXOFFICE'
          DataBinding.DataSource = DSCustomers
          Properties.DropDownListStyle = lsEditList
          Properties.KeyFieldNames = 'TAXOFFICE'
          Properties.ListColumns = <
            item
              FieldName = 'TAXOFFICE'
            end>
          Properties.ListOptions.ShowHeader = False
          Properties.ListSource = DSTaxOffice
          Style.Color = clWindow
          TabOrder = 3
          Width = 150
        end
        object cxDBLookupComboBox4: TcxDBLookupComboBox
          Left = 110
          Top = 160
          DataBinding.DataField = 'CITY'
          DataBinding.DataSource = DSCustomers
          ParentFont = False
          Properties.DropDownListStyle = lsEditList
          Properties.KeyFieldNames = 'CITY'
          Properties.ListColumns = <
            item
              FieldName = 'CITY'
            end>
          Properties.ListOptions.ShowHeader = False
          Properties.ListSource = DSCity
          Style.Color = clWindow
          Style.Font.Charset = DEFAULT_CHARSET
          Style.Font.Color = clWindowText
          Style.Font.Height = -11
          Style.Font.Name = 'Tahoma'
          Style.Font.Style = []
          Style.IsFontAssigned = True
          TabOrder = 7
          Width = 150
        end
        object cxDBLookupComboBox1: TcxDBLookupComboBox
          Left = 110
          Top = 206
          Hint = #935#974#961#945
          DataBinding.DataField = 'COUNTRY'
          DataBinding.DataSource = DSCustomers
          ParentFont = False
          Properties.DropDownListStyle = lsEditList
          Properties.KeyFieldNames = 'COUNTRY'
          Properties.ListColumns = <
            item
              Caption = #935#974#961#945
              FieldName = 'COUNTRY'
            end>
          Properties.ListOptions.ShowHeader = False
          Properties.ListSource = DSCountry
          Style.Edges = [bLeft, bTop, bRight, bBottom]
          Style.Font.Charset = GREEK_CHARSET
          Style.Font.Color = clWindowText
          Style.Font.Height = -11
          Style.Font.Name = 'Tahoma'
          Style.Font.Style = []
          Style.IsFontAssigned = True
          TabOrder = 9
          Width = 145
        end
        object cxDBTextEdit2: TcxDBTextEdit
          Left = 110
          Top = 183
          DataBinding.DataField = 'POSTCODE'
          DataBinding.DataSource = DSCustomers
          TabOrder = 8
          Width = 91
        end
        object cxDBLookupComboBox5: TcxDBLookupComboBox
          Left = 451
          Top = 210
          Hint = #932#961#972#960#959#962' '#960#955#951#961#969#956#942#962
          DataBinding.DataField = 'PAYMETH_ID'
          DataBinding.DataSource = DSCustomers
          ParentFont = False
          Properties.KeyFieldNames = 'METHOD_ID'
          Properties.ListColumns = <
            item
              FieldName = 'DESCRIPTION'
            end>
          Properties.ListOptions.ShowHeader = False
          Properties.ListSource = DSPaymentMethod
          Style.Color = clWindow
          Style.Font.Charset = GREEK_CHARSET
          Style.Font.Color = clWindowText
          Style.Font.Height = -12
          Style.Font.Name = 'Tahoma'
          Style.Font.Style = []
          Style.ButtonTransparency = ebtNone
          Style.IsFontAssigned = True
          TabOrder = 17
          Width = 233
        end
        object cxDBMemo1: TcxDBMemo
          Left = 500
          Top = 19
          DataBinding.DataField = 'DETAILS'
          DataBinding.DataSource = DSCustomers
          Properties.ScrollBars = ssVertical
          TabOrder = 11
          Height = 59
          Width = 185
        end
        object cxDBTextEdit1: TcxDBTextEdit
          Left = 111
          Top = 85
          DataBinding.DataField = 'VAT_VIES'
          DataBinding.DataSource = DSCustomers
          ParentFont = False
          Style.Font.Charset = DEFAULT_CHARSET
          Style.Font.Color = clWindowText
          Style.Font.Height = -12
          Style.Font.Name = 'Tahoma'
          Style.Font.Style = []
          Style.IsFontAssigned = True
          TabOrder = 4
          Width = 234
        end
        object cxDBTextEdit3: TcxDBTextEdit
          Left = 451
          Top = 161
          DataBinding.DataField = 'EMAIL'
          DataBinding.DataSource = DSCustomers
          ParentFont = False
          Style.Font.Charset = GREEK_CHARSET
          Style.Font.Color = clWindowText
          Style.Font.Height = -12
          Style.Font.Name = 'Tahoma'
          Style.Font.Style = []
          Style.IsFontAssigned = True
          TabOrder = 15
          Width = 330
        end
        object editPercent: TcxDBTextEdit
          Left = 110
          Top = 229
          DataBinding.DataField = 'DISCOUNT'
          DataBinding.DataSource = DSCustomers
          ParentFont = False
          Style.Font.Charset = GREEK_CHARSET
          Style.Font.Color = clWindowText
          Style.Font.Height = -12
          Style.Font.Name = 'Tahoma'
          Style.Font.Style = []
          Style.IsFontAssigned = True
          TabOrder = 10
          OnExit = editPercentExit
          Width = 91
        end
        object cxDBTextEdit5: TcxDBTextEdit
          Left = 451
          Top = 186
          DataBinding.DataField = 'SECONDARY_EMAIL'
          DataBinding.DataSource = DSCustomers
          ParentFont = False
          Style.Font.Charset = GREEK_CHARSET
          Style.Font.Color = clWindowText
          Style.Font.Height = -12
          Style.Font.Name = 'Tahoma'
          Style.Font.Style = []
          Style.IsFontAssigned = True
          TabOrder = 16
          Width = 330
        end
        object cxDBTextEdit4: TcxDBTextEdit
          Left = 451
          Top = 111
          DataBinding.DataField = 'PHONE1'
          DataBinding.DataSource = DSCustomers
          ParentFont = False
          Style.Font.Charset = GREEK_CHARSET
          Style.Font.Color = clWindowText
          Style.Font.Height = -12
          Style.Font.Name = 'Tahoma'
          Style.Font.Style = []
          Style.IsFontAssigned = True
          TabOrder = 12
          Width = 120
        end
        object cxDBTextEdit6: TcxDBTextEdit
          Left = 615
          Top = 111
          DataBinding.DataField = 'PHONE2'
          DataBinding.DataSource = DSCustomers
          ParentFont = False
          Style.Font.Charset = GREEK_CHARSET
          Style.Font.Color = clWindowText
          Style.Font.Height = -12
          Style.Font.Name = 'Tahoma'
          Style.Font.Style = []
          Style.IsFontAssigned = True
          TabOrder = 13
          Width = 120
        end
        object cxDBTextEdit7: TcxDBTextEdit
          Left = 451
          Top = 136
          DataBinding.DataField = 'FAX'
          DataBinding.DataSource = DSCustomers
          ParentFont = False
          Style.Font.Charset = GREEK_CHARSET
          Style.Font.Color = clWindowText
          Style.Font.Height = -12
          Style.Font.Name = 'Tahoma'
          Style.Font.Style = []
          Style.IsFontAssigned = True
          TabOrder = 14
          Width = 158
        end
        object editVatNo: TcxDBTextEdit
          Left = 111
          Top = 60
          DataBinding.DataField = 'AFM'
          DataBinding.DataSource = DSCustomers
          ParentFont = False
          Properties.OnChange = cxDBTextEdit8PropertiesChange
          Style.Font.Charset = GREEK_CHARSET
          Style.Font.Color = clWindowText
          Style.Font.Height = -12
          Style.Font.Name = 'Tahoma'
          Style.Font.Style = []
          Style.IsFontAssigned = True
          TabOrder = 2
          Width = 120
        end
        object cxDBTextEdit8: TcxDBTextEdit
          Left = 111
          Top = 110
          DataBinding.DataField = 'ADDRESS1'
          DataBinding.DataSource = DSCustomers
          ParentFont = False
          Style.Font.Charset = DEFAULT_CHARSET
          Style.Font.Color = clWindowText
          Style.Font.Height = -12
          Style.Font.Name = 'Tahoma'
          Style.Font.Style = []
          Style.IsFontAssigned = True
          TabOrder = 5
          Width = 234
        end
        object cxDBTextEdit9: TcxDBTextEdit
          Left = 111
          Top = 135
          DataBinding.DataField = 'ADDRESS2'
          DataBinding.DataSource = DSCustomers
          ParentFont = False
          Style.Font.Charset = DEFAULT_CHARSET
          Style.Font.Color = clWindowText
          Style.Font.Height = -12
          Style.Font.Name = 'Tahoma'
          Style.Font.Style = []
          Style.IsFontAssigned = True
          TabOrder = 6
          Width = 234
        end
        object cxDBCheckBox1: TcxDBCheckBox
          Left = 327
          Top = 234
          Caption = #928#945#961#945#954#961#940#964#951#963#951' '#966#972#961#959#965
          DataBinding.DataField = 'WITHHOLD_TAX'
          DataBinding.DataSource = DSCustomers
          ParentFont = False
          Properties.Alignment = taRightJustify
          Properties.DisplayChecked = '1'
          Properties.DisplayUnchecked = '0'
          Properties.DisplayGrayed = '0'
          Properties.NullStyle = nssUnchecked
          Properties.ValueChecked = '1'
          Properties.ValueGrayed = 0
          Properties.ValueUnchecked = '0'
          Style.Font.Charset = DEFAULT_CHARSET
          Style.Font.Color = clWindowText
          Style.Font.Height = -12
          Style.Font.Name = 'Tahoma'
          Style.Font.Style = []
          Style.IsFontAssigned = True
          TabOrder = 18
        end
      end
      object TabSheet2: TTabSheet
        Caption = #928#945#961#945#963#964#945#964#953#954#940
        ImageIndex = 1
        object JvPanel2: TJvPanel
          Left = 0
          Top = 0
          Width = 321
          Height = 259
          FlatBorder = True
          Align = alLeft
          BorderStyle = bsSingle
          Caption = 'JvPanel2'
          TabOrder = 0
          object GridCustInvoices: TcxGrid
            Left = 1
            Top = 1
            Width = 315
            Height = 253
            Align = alClient
            BorderStyle = cxcbsNone
            TabOrder = 0
            DragOpening = False
            LookAndFeel.Kind = lfStandard
            LookAndFeel.NativeStyle = True
            object ViewCustInvoices: TcxGridDBTableView
              Navigator.Buttons.CustomButtons = <>
              Navigator.Buttons.First.Visible = False
              Navigator.Buttons.PriorPage.Hint = #928#961#959#951#947#959#973#956#949#957#951' '#963#949#955#943#948#945
              Navigator.Buttons.Prior.Enabled = False
              Navigator.Buttons.Prior.Visible = False
              Navigator.Buttons.Next.Enabled = False
              Navigator.Buttons.Next.Visible = False
              Navigator.Buttons.NextPage.Hint = #917#960#972#956#949#957#951' '#963#949#955#943#948#945
              Navigator.Buttons.Last.Visible = False
              Navigator.Buttons.Insert.Enabled = False
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
              Navigator.Visible = True
              OnCellDblClick = ViewCustInvoicesCellDblClick
              DataController.DataModeController.SmartRefresh = True
              DataController.DataSource = DSInvoices
              DataController.Summary.DefaultGroupSummaryItems = <
                item
                  Kind = skSum
                end>
              DataController.Summary.FooterSummaryItems = <
                item
                  Kind = skSum
                  FieldName = 'PRICEWVAT'
                  Column = ViewCustInvoicesPRICEWVAT
                end>
              DataController.Summary.SummaryGroups = <>
              FilterRow.ApplyChanges = fracImmediately
              NewItemRow.InfoText = #917#953#963#945#947#969#947#942
              OptionsBehavior.NavigatorHints = True
              OptionsCustomize.ColumnHiding = True
              OptionsCustomize.ColumnsQuickCustomization = True
              OptionsCustomize.ColumnsQuickCustomizationReordering = qcrEnabled
              OptionsData.Appending = True
              OptionsSelection.CellSelect = False
              OptionsSelection.MultiSelect = True
              OptionsView.FocusRect = False
              OptionsView.NavigatorOffset = 10
              OptionsView.NoDataToDisplayInfoText = '<'#916#949#957' '#965#960#940#961#967#959#965#957' '#948#949#948#959#956#941#957#945'>'
              OptionsView.Footer = True
              OptionsView.GridLines = glVertical
              OptionsView.GroupByBox = False
              OptionsView.HeaderEndEllipsis = True
              OptionsView.Indicator = True
              Styles.Background = StyleMain
              Styles.ContentEven = StyleEven
              Styles.ContentOdd = StyleOdd
              object ViewCustInvoicesINVOICE_ID: TcxGridDBColumn
                Caption = 'ID'
                DataBinding.FieldName = 'INVOICE_ID'
                Visible = False
              end
              object ViewCustInvoicesINVCODE: TcxGridDBColumn
                Caption = #922#969#948#953#954#972#962
                DataBinding.FieldName = 'INVCODE'
              end
              object ViewCustInvoicesINVDATE: TcxGridDBColumn
                Caption = #919#956#949#961#959#956#951#957#943#945
                DataBinding.FieldName = 'INVDATE'
              end
              object ViewCustInvoicesPAYMETHOD: TcxGridDBColumn
                Caption = #932#961'. '#928#955#951#961#969#956#942#962
                DataBinding.FieldName = 'PAYMETHOD'
                Width = 100
              end
              object ViewCustInvoicesPRICEWVAT: TcxGridDBColumn
                Caption = #913#958#943#945' '#956#949' '#934#928#913
                DataBinding.FieldName = 'PRICEWVAT'
              end
            end
            object ViewMonthGroup: TcxGridDBTableView
              Navigator.Buttons.CustomButtons = <>
              DataController.DataSource = DSMonthGroup
              DataController.Summary.DefaultGroupSummaryItems = <>
              DataController.Summary.FooterSummaryItems = <>
              DataController.Summary.SummaryGroups = <>
              OptionsSelection.CellSelect = False
              OptionsSelection.MultiSelect = True
              OptionsView.GroupByBox = False
              Styles.Background = StyleMain
              Styles.ContentEven = StyleEven
              Styles.ContentOdd = StyleOdd
              object ViewMonthGroupMONTH_DATE: TcxGridDBColumn
                Caption = #924#942#957#945#962
                DataBinding.FieldName = 'MONTH_DATE'
              end
              object ViewMonthGroupSUM: TcxGridDBColumn
                Caption = #924#949' '#960#943#963#964#969#963#951
                DataBinding.FieldName = 'SUM_DUE'
              end
              object ViewMonthGroupColumn1: TcxGridDBColumn
                Caption = #935#969#961#943#962' '#960#943#963#964#969#963#951
                DataBinding.FieldName = 'SUM_0DUE'
              end
            end
            object GridLevelCustInvoices: TcxGridLevel
              GridView = ViewCustInvoices
            end
          end
        end
        object JvDotNetButton3: TJvDotNetButton
          Left = 327
          Top = 3
          Width = 99
          Height = 25
          Hint = #922#945#964#945#967#974#961#951#963#951' '#960#945#961#945#963#964#945#964#953#954#959#973' '#954#945#953' '#949#954#964#973#960#969#963#951
          Caption = #925#941#959' '#960#945#961#945#963#964#945#964#953#954#972
          TabOrder = 1
          OnClick = JvDotNetButton3Click
        end
        object radioMonthlyGroup: TcxRadioButton
          Left = 327
          Top = 180
          Width = 113
          Height = 17
          Caption = #924#951#957#953#945#943#945
          TabOrder = 2
          OnClick = radioMonthlyGroupClick
        end
        object cxRadioButton2: TcxRadioButton
          Left = 327
          Top = 157
          Width = 113
          Height = 17
          Caption = #908#955#945' '#964#945' '#960#945#961#945#963#964#945#964#953#954#940
          Checked = True
          TabOrder = 3
          TabStop = True
          OnClick = radioMonthlyGroupClick
        end
        object JvDotNetButton5: TJvDotNetButton
          Left = 327
          Top = 34
          Width = 99
          Height = 25
          Caption = #913#960#959#963#964#959#955#942' '#956#949' Email'
          TabOrder = 4
          OnClick = JvDotNetButton5Click
        end
        object cxButton1: TcxButton
          Left = 327
          Top = 65
          Width = 99
          Height = 25
          Caption = #928#961#959#946#959#955#942
          TabOrder = 5
          OnClick = cxButton1Click
        end
      end
      object TabSheet3: TTabSheet
        Caption = #928#955#951#961#969#956#941#962
        ImageIndex = 2
        object JvPanel3: TJvPanel
          Left = 0
          Top = 0
          Width = 257
          Height = 259
          FlatBorder = True
          Align = alLeft
          BorderStyle = bsSingle
          Caption = 'JvPanel2'
          ParentBackground = False
          TabOrder = 0
          object JvPanel4: TJvPanel
            Left = 1
            Top = 1
            Width = 251
            Height = 32
            FlatBorder = True
            Align = alTop
            TabOrder = 0
            object JvToolBar2: TJvToolBar
              Left = 74
              Top = 1
              Width = 176
              Height = 30
              Align = alRight
              ButtonHeight = 30
              ButtonWidth = 32
              Caption = 'JvToolBar1'
              EdgeInner = esNone
              Images = ImageList1
              ParentShowHint = False
              ShowHint = False
              TabOrder = 0
              Transparent = False
              object ToolsPriorPayments: TToolButton
                Left = 0
                Top = 0
                Hint = #928#961#959#951#947#959#973#956#949#957#959
                Caption = 'ToolPrevious'
                ImageIndex = 0
                OnClick = ToolsPriorPaymentsClick
              end
              object ToolNextPayments: TToolButton
                Left = 32
                Top = 0
                Hint = #917#960#972#956#949#957#959
                Caption = 'ToolNext'
                ImageIndex = 1
                ParentShowHint = False
                ShowHint = True
                OnClick = ToolNextPaymentsClick
              end
              object ToolButton5: TToolButton
                Left = 64
                Top = 0
                Width = 8
                Caption = 'ToolButton4'
                ImageIndex = 3
                Style = tbsSeparator
              end
              object ToolAddPayments: TToolButton
                Left = 72
                Top = 0
                Hint = #917#953#963#945#947#969#947#942
                Caption = 'ToolAdd'
                ImageIndex = 2
                OnClick = ToolPAddClick
              end
              object TollDeletePayments: TToolButton
                Left = 104
                Top = 0
                Hint = #916#953#945#947#961#945#966#942
                Caption = 'ToolDelete'
                ImageIndex = 3
                OnClick = TollDeletePaymentsClick
              end
              object ToolButton9: TToolButton
                Left = 136
                Top = 0
                Width = 8
                Caption = 'ToolButton8'
                ImageIndex = 6
                Style = tbsSeparator
              end
              object ToolRefreshPayments: TToolButton
                Left = 144
                Top = 0
                Hint = #913#957#945#957#941#969#963#951
                Caption = 'ToolRefresh'
                ImageIndex = 7
                OnClick = ToolRefreshPaymentsClick
              end
            end
          end
          object cxGrid1: TcxGrid
            Left = 1
            Top = 33
            Width = 251
            Height = 221
            Align = alClient
            BorderStyle = cxcbsNone
            TabOrder = 1
            DragOpening = False
            LookAndFeel.Kind = lfStandard
            LookAndFeel.NativeStyle = True
            object cxGridDBTableView1: TcxGridDBTableView
              Navigator.Buttons.CustomButtons = <>
              Navigator.Buttons.First.Visible = False
              Navigator.Buttons.PriorPage.Hint = #928#961#959#951#947#959#973#956#949#957#951' '#963#949#955#943#948#945
              Navigator.Buttons.Prior.Enabled = False
              Navigator.Buttons.Prior.Visible = False
              Navigator.Buttons.Next.Enabled = False
              Navigator.Buttons.Next.Visible = False
              Navigator.Buttons.NextPage.Hint = #917#960#972#956#949#957#951' '#963#949#955#943#948#945
              Navigator.Buttons.Last.Visible = False
              Navigator.Buttons.Insert.Enabled = False
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
              Navigator.Visible = True
              DataController.DataSource = DSPayments
              DataController.Summary.DefaultGroupSummaryItems = <
                item
                  Kind = skSum
                end>
              DataController.Summary.FooterSummaryItems = <>
              DataController.Summary.SummaryGroups = <>
              FilterRow.ApplyChanges = fracImmediately
              NewItemRow.InfoText = #917#953#963#945#947#969#947#942
              OptionsBehavior.NavigatorHints = True
              OptionsCustomize.ColumnHiding = True
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
              OptionsView.Indicator = True
              Styles.Background = StyleMain
              Styles.ContentEven = StyleEven
              Styles.ContentOdd = StyleOdd
              object cxGridDBTableView1PAYMENT_ID: TcxGridDBColumn
                Caption = 'ID'
                DataBinding.FieldName = 'PAYMENT_ID'
                Width = 48
              end
              object cxGridDBTableView1PAY_DATE: TcxGridDBColumn
                Caption = #919#956#949#961'/'#957#943#945
                DataBinding.FieldName = 'PAY_DATE'
              end
              object cxGridDBTableView1VALUE: TcxGridDBColumn
                Caption = #913#958#943#945
                DataBinding.FieldName = 'VALUE'
              end
            end
            object cxGridLevel1: TcxGridLevel
              GridView = cxGridDBTableView1
            end
          end
        end
        object JvDotNetButton2: TJvDotNetButton
          Left = 263
          Top = 3
          Width = 99
          Height = 25
          Hint = #922#945#964#945#967#974#961#951#963#951' '#960#945#961#945#963#964#945#964#953#954#959#973' '#954#945#953' '#949#954#964#973#960#969#963#951
          Caption = #925#941#945' '#960#955#951#961#969#956#942
          TabOrder = 1
          OnClick = JvDotNetButton2Click
        end
      end
    end
  end
  object ImageList1: TImageList
    Height = 24
    Width = 24
    Left = 368
    Top = 152
    Bitmap = {
      494C01010C001100640018001800FFFFFFFFFF10FFFFFFFFFFFFFFFF424D3600
      0000000000003600000028000000600000006000000001002000000000000090
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000000000000000000000000000BF641F00E8BC9C00000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000009FCE9F0056A8560029942A00148A
      180014881700298F2A0057A25700A0C9A0000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000000000000000000000000000098E1100098F1300078B1000088B
      0F00068A0E0007890D0007880E00048408000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000C15E0E00DF954600B8520100EBBA99000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000088CE880013961500109D230018A8320019A7320015A2
      2C0013A12B0013A12900139F25000B901700138414008AC28A00000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000000000000000000000000000189622004DD97C0036CA660038CA
      660037C7630036C7630034C460000B8D16000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000F6CCA500C65F0600E8A65B00F9C07400EFAE5900B34C0100E7B6
      9500000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      00000000000035A8350018AE320025BE4D0025BE490023BD48001EBA420020C2
      41001CB83B0015A72F00139D2700119A2300139F25000A8F1600379037000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000000000000000000000000000199723005FE48A003CD26C003BD1
      6A003CD06A003ACF690037C964000C8E17000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000C9610100E5A25900F2BB7400F0B26300F4B25A00EFA44600B049
      0100E4B394000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000021A6220026BF4E002AC4550028C1500026BF4E0026BF4D001EC44000D0F8
      D70076E78C001AC03F001DB83E0019AD330012982300109922000F981F00238A
      2300000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000001A98240067E88F003FD570003ED5
      6E003DD26C003BD16B0038CB66000C8E17000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000F1B67E00C7620400EAAD6300EFAE5D00EFA74E00F2A84600ED9C
      3500AC450000E2AE900000000000000000000000000000000000000000000000
      00000000000000000000000000000000000000000000000000000000000034AF
      34002DC459002DC75B002BC457002BC457002AC356002AC354001CC63D00FFFF
      FF00FFFFFF006AE585001BC042001FB941001DB83E00149D2800109821000F9A
      1F00379337000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000001C9B25006CEB930040D770003FD5
      6F003ED46E003ED36D003ACB67000D9119000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      00000000000000000000EEB48200C25C0200E9A25000EBA34900ED9D3A00F09D
      3100EB942200A7400000DFAA8D00000000000000000000000000000000000000
      000000000000000000000000000000000000000000000000000085D7870031BE
      510030CB65002FC860002FC85F002FC860002EC75E002DC65D001EC63D00FFFF
      FF00FFFFFF00FFFFFF0065E683001DC143001EB941001EBA4000149D28001199
      23000B90170089C3890000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000001D9B270074ED980044D9750041D7
      710040D670003ED56F003BCE69000E921A000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000EDB38400BE560200E6973D00EA993500E993
      2700EE941D00EC8C1100A43C0000DBA68B000000000000000000000000000000
      00000000000000000000000000000000000000000000000000001EB02E0052D7
      830032CC650033CC670033CC660033CC670032CB650031CA67001CC64000F1FB
      F000FFFFFF00FFFFFF00FFFFFF0061E77F001DBF450020BA43001FB83E001298
      2400129F26001285130000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000000000000000000000000000189B230070EC970048DC770042D9
      730041D8720041D670003CCE6A000E9219000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000000000000000000000000000EAB28600B8520100E38D2B00E890
      2100E7891400ED8A0800EA830000A0390000D8A2880000000000000000000000
      000000000000000000000000000000000000000000009DDCA4003EC761004BD6
      7B0036D06A0038D16D0038D16D0039D26D0037D06C0036CF6B002FCD640036CC
      4F00FFFFFF00FFFFFF00FFFFFF00FFFFFF005AE37A001CBE45001FB9430019AC
      3400129A25000C9217009FCA9F000000000000000000D88D4600CA6C1600C869
      1600C6661600C2621600C1601600BD601600BB5B1600B9591600B6581600B355
      1600B0531600AD4F1600AA4D1600A94A1600A5481600A14616009F4216009E40
      16009C3F16009D3F1600B46B490000000000000000000D9717001BA42A00129C
      2100129C2100139B2100119A200012981F001BA52E004CE37F0047DD770044DB
      750043D9730041D771003DD06B000F8D19000A87110009871100098610000984
      10000883100008810F00037B0600000000000000000000000000000000000000
      00000000000000000000000000000000000000000000E9B28900B44E0100E084
      1900E5860D00E5800200EC860000EA8400009D360000D79F8500000000000000
      0000000000000000000000000000000000000000000056C1620064DE8F0049D7
      7A003BD7720028CA570022C74C0023C84E0022C84D0023C9500022C9510018C6
      45002BCB4600FBFDFB00FFFFFF00FFFFFF00FFFFFF0052E072001CBE430020BB
      4200149B2700149F280055A455000000000000000000C86B1500C9947A00CD95
      7400D0997400D1976C00D3946000D4915600D78F4900D98D3F00DC8C3400E08B
      2900E58B1E00EA8C1500EF8C0A00F58F0000FC960000FF9D0000FF9F0000FF9F
      0000FF9F0000FFA50000A74407000000000000000000129C1D008FFAAD0052E2
      81004CE07B004BDF7B004ADF7A0049DE780049DE790049DF7B0047DE780046DD
      770045DB750043DB750041D670003ED26C003ED06C003CD06B003BCD690039CD
      680038CC670034C46000057E0900000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000E8B18B00AE49
      0100DC790900E37D0000E57F0000EB850000EA8400009D360000D79D82000000
      0000000000000000000000000000000000000000000033BB480077E6A0004EDD
      800011B72F0081CE8000ABE0AA00A8E1AB00ABE3AD00B1E5B400B4E9B900B6EA
      BB00AEEBB700CAF2D100FFFFFF00FFFFFF00FFFFFF00FFFFFF0049DC67001DBE
      430019A7330015A12D00269226000000000000000000C86B1100C9917500CC92
      6E00CF966D00D1936600D1905B00D38D5000D48A4600D7893B00DA883200DD87
      2800E1871E00E5871400EA880A00EF8A0100F58F0000FC960000FF990000FF9A
      0000FF990000FFA00000A44107000000000000000000119D1C0095FAAF0069ED
      91005AE9870059E8870057E7850056E6840054E3820052E47F004DE17E0048DE
      790046DD770045DB750043DB750041D9730041D771003ED46E003DD46E003DD0
      6A003ACF690035C46000057F0900000000000000000000000000000000000000
      000000000000000000000000000000000000000000000000000000000000DBA4
      8000A7410000DF790000E17B0000E6800000EB850000ED8700009D360000E2B4
      9F00000000000000000000000000000000000000000027BC46007EE9A40059E4
      8C000EA91800FFFFFF00FFFFFF00FFFFFF00FFFFFF00FFFFFF00FFFFFF00FFFF
      FF00FFFFFF00FFFFFF00FFFFFF00FFFFFF00FFFFFF00FFFFFF00FFFFFF0039D3
      59001BB13C0016A12E00118C14000000000000000000C7691100C8917400CC91
      6D00CF966C00D1956600D1905C00D28D5100D48B4700D6893D00D9883300DD87
      2900E0871F00E4871600E9880C00EE890300F38D0000F9930000FF990000FF99
      0000FF990000FFA00000A34007000000000000000000119F1E0096FAB0006FEF
      950060EB8B005FEA8C005EE9890059E8870056E7850056E5820052E3800050E2
      7D0049DF790046DD770045DB750043D9730041D8720040D670003ED46E003DD2
      6C003AD06A0036C5610004800900000000000000000000000000000000000000
      000000000000000000000000000000000000000000000000000000000000B962
      2A00BC560100DD770000DE780000E17B0000EA840000C35D0000A44921000000
      0000000000000000000000000000000000000000000029BD4B0083ECA7006AEA
      990007A71300FFFFFF00FFFFFF00FFFFFF00FFFFFF00FFFFFF00FFFFFF00FFFF
      FF00FFFFFF00FFFFFF00FFFFFF00FFFFFF00FFFFFF00FFFFFF00F3FCF20028CB
      480021B7430019A43200128E15000000000000000000C6681100C8907400CB91
      6D00D0946E00D2946900D1915D00D28E5300D48B4900D6893F00D8883500DC87
      2B00DF872200E3871800E7880F00EC880600F18B0000F6900000FC960000FF99
      0000FF9A0000FFA00000A74207000000000000000000139F1E0099FBB20075F1
      990065ED900064ED8E0061EC8C005FEA8B005CE9890057E7860055E5830052E3
      800050E37D0048DE790046DD770044DB750042D9730041D771003FD56F003ED4
      6E003BD16B0036C6620005810A00000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000BC662800BE5B
      0800D8770A00D6710000DA740000E37D0000BC560000A34A2200000000000000
      000000000000000000000000000000000000000000003CC45A0084EEAA0080F0
      A90015BF370056B851007ECB7C007ECC7D0081CF800083D1850085D5890088D9
      8E007CD78600CCF0D100FFFFFF00FFFFFF00FFFFFF00ECFAEE0022C63F0022C0
      4E0024BD49001AA73600299929000000000000000000C5671100C7907400CB90
      6D00CE956D00D0966900D1915F00D28E5500D38C4B00D58A4100D8883700DB87
      2E00DE872500E2871C00E6871300EA880A00EE890200F38D0000F7910000FB95
      0000FF990000FF9F0000A7440700000000000000000012A01F009BFCB40082F4
      A10076F29B0075F1990072F097006FEF95006DEE940064EB8D0057E7860055E5
      830052E380004DE17E0047DE780049DD79004BDC790046DA750041D8720040D6
      6F003CD26C0035C7620005820B00000000000000000000000000000000000000
      00000000000000000000000000000000000000000000BE672500C0601000D47B
      1E00D2720E00D36F0300DB750000B9530000A54C240000000000000000000000
      0000000000000000000000000000000000000000000063CE7D0077ED9E0092F1
      B30055EE8A0044E475003CDE6C003BDE6B003BDE6B0039DC690038DC6B0014C4
      3A0055C95F00FFFFFF00FFFFFF00FFFFFF00EDFAED001EC1370027C6570029C2
      550025BF4D001BA8360058AE58000000000000000000C4671400C8937D00CB95
      7600CE987600D39B7400D3976B00D5945F00D6915500D88F4900DA8D3F00DE8E
      3700E18C2C00E58D2300E98D1900EE8F1000F18E0800F6910100FA940000FF99
      0000FF9A0000FFA30000A7440700000000000000000015A32100A8FFBF009BFC
      B3009AFCB30099FCB30098FBB20097FAB10096F9AF008AF4A50063EB8D0057E7
      860056E5820052E2800048DF790054E585007AF09E0078EE9B0073EC97006CE8
      930065E78D004FDA7C0006850E00000000000000000000000000000000000000
      000000000000000000000000000000000000C4682300C2661900D2803200CF77
      2100D0731500D6740B00B7520000AA5126000000000000000000000000000000
      00000000000000000000000000000000000000000000ABE5BA0056DD810091F5
      B3007AF1A20052EF870057F08B0056EF8A0055EE890054ED8A0035D7620056C4
      5C00FFFFFF00FFFFFF00FFFFFF00EBF7EA0019BB2F002CCA62002EC75F002AC3
      560028BE5000119F2300A3D4A3000000000000000000D1874500C2631100C05F
      1100BD5D1100BC5D1500BA5A1600B8581600B6561600B4551600B0521600AE4F
      1600AD531B00AA4F1B00A84D1B00A64B1B00A3491B00A1451B00A1441C00A249
      2000A2492000A3492000BA74530000000000000000000EA01B0022AB2F0020AA
      2D001FA82C0020A72C001FA62B001DA429002DAD380095F9AE006CED93005CE9
      890056E8850053E3800047DD7800159E2600179922001A992400199722001896
      2200179421001694210006850D00000000000000000000000000000000000000
      0000000000000000000000000000C76A2000C46D2400D0864600CE7D3400CE78
      2900D2792100B7550800AF572900000000000000000000000000000000000000
      00000000000000000000000000000000000000000000000000003BCE660091F9
      B4009BF7B90060F3910059F38D0059F38C0056EF890057F28F0011B02000FFFF
      FF00FFFFFF00FFFFFF00EDF7ED0014B6270033D1670033CD68002FC861002CC5
      5B0029BE5300159F180000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000001BA3260098FAB1006DEF94005FEA
      8B0059E8870055E5830046DA75000C9116000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      00000000000000000000C96B1D00C7753100D08F5A00CD844700CD7F3B00D07F
      3600B8591000B35C2B0000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000A0ECB4005EE4
      860098FBBA0098FAB8005EF791005BF58F0059F28C0058F4910029AF2F00FFFF
      FF00FFFFFF00ECF7EB000DAF1D0038D66D0039D26D0034CD680031CA620030C9
      5F0017AD300091D8910000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000001DA4290099FCB20071F0970061EC
      8C005EE9890056E7840046DB77000D9318000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000CE711B00CB7F4000D2997100CE8D5B00CD875100CF884A00BA5D
      1600B7632E000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000005BD4
      7E007EF5A6009EFFBF009BFEBC0063F8950058F48C005AF7930022AC2700FFFF
      FF00F0F7EE0008AA14003DDA72003ED8730039D26D0034CD670033CC670029C2
      53003FB43F000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000001EA529009AFBB40074F1990064ED
      8E0060EA8C0058E7860047DB76000D9418000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000D8955200C9712400D19A7600D0936700D0906100BC611C00BB67
      3000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      00004CD2750082F9A8009EFFBF009DFCBC0077F7A00057F68E0009AE1B009CD3
      970007A30E0040DE760043DC77003DD6700039D26D0037D06D002EC75C002EB0
      3300000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000001EA72A009AFCB30075F29A0065ED
      900060EB8B0058E8860048DC77000E9519000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      00000000000000000000D59B6000C4691700D29B7900BE672400BF6C32000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      00000000000060D2860062E48E0097FFBA0096FAB80092F7B40075F2A1003BCE
      5A0048E27C0045DF790040DA750042D876004AD97D002CC250004ABE51000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000001EA82C009BFCB40082F4A20077F1
      9A0071EF96006BED92004DDE7A000E961A000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000DFA66F00BD570100C4713400000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      00000000000000000000A8EDBF0044D370005CE186007EF1A5008BF6B10088F3
      AE0081EFA8007BEBA30068E1910045CC680033BC45009CE3A400000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      00000000000000000000000000000000000021AA2E00A8FFBF009BFDB3009AFA
      B30097FAB10096FAB0008CF8AB00139C21000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000000000000000000000000000EBC6A80000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000000000000000000000000000B8EBC70074D58F0051CB710042C9
      650041C761004CC665006DCD8000B4E5BA000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000FA01C0015A3230013A12100149F
      2100139E1F00139D1F00149D1F000C9718000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000002525
      B3001313AD005858C30000000000000000000000000000000000000000000000
      0000000000000000000000000000000000006363C100A7A7DC00000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      00000000000000000000000000000000000000000000E3C3A800C27E4A00BF68
      2100BE631900BA692D00CA947400000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      00000000000000000000000000000BC1F50018B3DE0071A89100000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      00000000000000000000000000000000000000000000000000001818B7001542
      F0001545E8000E2CC8000509AA006F69C3000000000000000000000000000000
      00000000000000000000000000002B147D000520C6000312AC002828AA005C5C
      C400000000000000000000000000000000000000000000000000000000000000
      000000000000000000000000000000000000C67B3500DF822700FFA43F00FFA3
      3B00FFA03600FFA03000F48B2100BE5B0F00DFAD900000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000002AEF300DEFEFF00F6FFFF008AE5F8001BC3EE0048BB
      C200AFB478000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000000000000000000000000000000000009494DC000E27D8001B4F
      FF00194AFB001948F5001643E300091CB7003321940000000000000000000000
      00000000000000000000351F85000A29D6000D3EF4000B39E600072ED400041C
      B9002424A9000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000C6711D00FEA74D00FFAA4C00FFA34400FFA1
      3F00FFA33B00FF9D3300FD942900FF9F2900DB711200D79C7900000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000B97FE008CE7F900ECFFFF00E0FFFF00DCE7E700D9D9D900B6DD
      E10055CEEA0003B0EB000583ED00000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000000000000000000000000000328B11001B880B00809826000000
      0000000000000000000000000000000000000000000000000000000000000000
      00000000000000000000000000000000000000000000342DAC002554FC002356
      FF001D4FFF001C4DFE001A4BFB001947F000102DC600020C9A00000000000000
      000000000000271A90000C2DDA001142F8000E3FF4000D3CF2000B39EE000A37
      E800041FBB003838B10000000000000000000000000000000000DA9656000000
      00000000000000000000C7761F00FFB05B00FFAD5600FFA94E00FFA94C00F398
      3900C66B1700CB865100D3956C00C77E4C00C6641100BE570600000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000001BC6F400EDFFFF00BDEEF30068AFCB006B9AA80089ABB000BACA
      CA00E9EEEE00FBFFFF0099EAFB0022CCFA0004A8FC000974F800000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      00000000000000000000000000000B91090014B02B0014B12900099A13005690
      2300000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000078475E000D0CB6002957
      F9002456FF002051FF001F51FF001D4EFD001B4BF4001338CE000007A0003960
      5200312FB1000F31DE001446FC001243F8001040F5000E3EF4000D3DF4000B3A
      EF000A36E5000212AC00A1A1DB000000000000000000CA782100FFCA8D00CF7C
      220000000000CE873D00FBB46800FFB36600FFAD5A00FFAF5A00E48E3300C78E
      53000000000000000000000000000000000000000000CB875A00BC6227000000
      0000000000000000000000000000000000000000000000000000000000000000
      00000CA0FE00ACF4FD00D4FFFF00D2FFFF00ABE0E800525E60003F5A64005689
      9C0098D2DF00CAF1F500F5FFFF00FFFFFF00DBFDFF0065DCF80001B8F700058F
      F900000000000000000000000000000000000000000000000000000000000000
      000000000000000000000D960C0017B22F0013AD2B0012AB260013AF27000899
      120070C270000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000352BB0002D5D
      FE002859FF002657FF002455FF002355FF002051FF001D4CF700153DD6000000
      A6001131DE00184CFE001547F9001444F8001243F7001040F5000E3EF4000C3D
      F3000C3CF200072CD5005656BD000000000000000000CE853100FFCF9A00FDC2
      8500BF6A1200E8A05300FFC48700FFBA7400FFB56700EE9B4300CD9960000000
      0000000000000000000000000000000000000000000000000000DC9E76000000
      0000000000000000000000000000000000000000000000000000000000000000
      00001FC4F100DFFFFF00D0FFFF00D1FFFF00CBF5F5006C7F87007C8DA8004162
      54002C894A00399F730074B7D000A6D9E600DAFBFB00FFFFFF00FFFFFF00A5EE
      FC002FD0FA0002A6ED0067B7EB00000000000000000000000000000000000000
      0000000000000D9A0D0019B5350016B0300015AE2C0014AD290014AD270013AF
      29000899120072C3720000000000000000000000000000000000000000000000
      00000000000000000000000000000000000000000000000000001010C1003C71
      FF002F61FF002B5BFF00295AFF002859FF002758FF002455FF001D4EF8001B4B
      F3001C4EFD001B4AFD001848FB001546F9001444F8001243F7001040F5000F41
      F8000B34E7000B0EAD00A4A4E0000000000000000000D18F4700FFD4A400FFCD
      9B00F6BB7E00FFCB9500FFC58B00FFC28300FFBA7300CC803000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000E85
      EC008CECF900C2FCFC006CBAD2008ED5E300B2F0F4009DC1D00056A98E0026B8
      420040C95E00209A31004EAE770070B6CF0064ACC9007DBED300B3DFEA00EEFF
      FF00FFFFFF009AEAFA0043CCF300000000000000000000000000000000000000
      00000E9D0F001BB63B001AB4350018B1310016AF2F0013AE2A0013AD290013AD
      280014B028000899120075C57500000000000000000000000000000000000000
      00000000000000000000000000000000000000000000000000003228B3002239
      DA004275FF003769FF002F60FF002C5DFF002A5AFF00285AFF002657FF002152
      FE001E4FFF001C4DFF001A4BFD001849FB001546F9001544F9001347FB000B29
      D9002929AF0000000000000000000000000000000000DBA76F00FCCC9B00FFD1
      A200FFD0A000FFCC9900FFC99100FFC99000D98C320000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      00000000000000000000000000000000000000000000000000000000000000AA
      E000D0FFFF00C0FFFF0093DCE90072C2D60060B3CC006EB3CC0027BE4A006AFF
      9E0056E57F003FC25C001B912A0087CF9E00BEEEF40091CEDD0061ABC7009FD1
      E000D5FDFF0023C5F60000000000000000000000000000000000000000000EA1
      0F0020B941001CB53A001AB3360019B2340017B132003DBE520027B43C000EAC
      260013AC280014B02A000899120077C577000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000751
      65000711BA003B63F4004273FF003565FF002F60FF002B5CFF00295AFF002859
      FF002354FF001F50FE001C4EFE001A4BFE001949FC00164AFF000920CC004848
      BD000000000000000000000000000000000000000000E7C39800F2BF8700FFD7
      AE00FFD2A500FFD0A000FFCA9800FDC48800C474200000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      00000000000000000000000000000000000000000000000000000000000046CE
      ED00C5FFFF00BBFFFF00BFFFFF00C6FFFF00BAFAFD0087DDD30040DC6B0069FF
      9A0061F6910052DE78003BBC5600168B23009FDCB800DFFFFF00DDFFFF00EEFF
      FF0010C1EF00000000000000000000000000000000000000000013A5160022BB
      45001FB840001DB63B001DB639001BB53A0017B031000E9F170076D1860041BF
      56000EAC250014AE290014B029000898120073B8660000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000062598002338D700487AFF003A6BFF003162FF002D5EFF002A5A
      FF002758FF002354FF001F50FE001D4EFE001C4FFF00081AC7005645A6000000
      0000000000000000000000000000000000000000000000000000E7AD6500FFE4
      C800FFD5AC00FFD1A300FFCD9A00FFC99300F4B26C00E2933900CE8227000000
      0000000000000000000000000000000000000000000000000000DDB19A00D298
      7C00CC8D6F0000000000000000000000000000000000000000001ABFBF008EF1
      F900AEFAFB009BEAF100B8FFFF00BFFFFF00C1FFFF00C4FFFF0061E0940055EF
      830066FF98005FF48F004FDA740037B650001386220091D6C200E7FFFF0055D6
      F6008DCBC900000000000000000000000000000000002BAF350044C765001FBA
      43001FB841001EB73E001FB840001AB33600319E1C00000000001092040072D1
      84003FBF53000EAC260014AD290016B12B00089812006A952F00000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000000000000F5C79000A11BC00406CFB003768FF003262FF002E5F
      FF002A5BFF002758FF002455FE001D4FFB00091AC6002C3E7900000000000000
      0000000000000000000000000000000000000000000000000000E29B3E00FFF3
      E400FFDCBA00FFD3A600FFCF9F00FFCC9700FFC99000FFC58300EA9841000000
      000000000000D0854A00CC732900C5712E00CF844700DB986200E6AE7F00F3C5
      9900FACC9F00BE6C3900000000000000000000000000000000000DBAE700BFFF
      FF0085DCE80055B1CC005AB4CC0070C6D9008EDEE900ABF6F900BCFEF9004BD0
      79005CF68A0067FF99005DEF89004AD46E0031AF490018852800A2EBDE0039BC
      E40000000000000000000000000000000000000000001EAF300076D8910017B8
      3D001FBA430021BB44001CB639002B9A0D000000000000000000000000000E99
      0E0074D285003DBF53000EAC260014AD2A0015B12D00089812007EB657000000
      0000000000000000000000000000000000000000000000000000000000000000
      00000000000000000000000000000209AA004273FF003A6AFF003565FF003262
      FF002E5FFF002A5AFF002657FE001E4EF800102DCA0001248600000000000000
      0000000000000000000000000000000000000000000000000000E89F3600FEDB
      B600FAD6AD00F3C38800EDAE6200E39D4600DB8C2C00E28C2500DF9A47000000
      0000E5C5AD00E89F5B00FFD39700FFD19B00FFD4A300FFD7AB00FFD9B000FFD8
      B100FFDFB600BF6C33000000000000000000000000000000000021BEE800B6FF
      FF00ACFFFF00A9FFFF0093E9F0007AD1E0005DB7D00056B1CB005DB6CE0072D1
      CE003ED06D0061FB900067FF99005BEC860048CF69002DA741000C8634000000
      0000000000000000000000000000000000000000000084CD8E0046C161006FD5
      8B0038C45B0026BA46002DA92300000000000000000000000000000000000000
      00000D990C0074D285003ABD500010AE270014AD2B0015B12C000898120081B5
      5500000000000000000000000000000000000000000000000000000000000000
      0000000000000000000017596B002740DF004576FF003D6DFF003969FF003565
      FF003262FF002D5EFF00295BFF002555FE001C4CEF000A1AB800034967000000
      000000000000000000000000000000000000000000000000000000000000EDB6
      6900F0BC7700F3CC970000000000000000000000000000000000000000000000
      000000000000C4702300DB935000F2B67D00FFCE9B00FFD0A000FFD3A600FFD4
      A900FFDAAE00D0885000000000000000000000000000A4DEF0004FD3EC00AFFF
      FF00A8FFFF00AAFFFF00ACFFFF00B1FFFF00B5FFFF00A4F7F9008ADEE9006DC3
      D8004BBEB00041D96E0062FC930065FD980057E8820042C9610021A637000478
      170000000000000000000000000000000000000000000000000075D3830028B9
      3F0041BF570034B43D0000000000000000000000000000000000000000000000
      0000000000000B980B0076D288003ABD500010AE280015AF2B0017B32E000898
      120083B554000000000000000000000000000000000000000000000000000000
      000000000000000000001719BD004D7DFF004675FF00406FFF003D6EFF00396A
      FF003A6BFF003565FF002C5CFF00295AFF002252FD001947E3000209AD000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000B0561C00FDC79100FFCC9A00FFCF9E00FFD0
      A000FFD4A300E3A36A00D9B09D00000000000000000073C9E70074EAF70091F1
      F50068CBDC0081E1EB0099F5F800ABFFFF00AEFFFF00B1FFFF00B3FFFF00B6FF
      FF00B5FFFF006CE7B4004CE6790064FE940062FE95004EEA7C00598C65007B6D
      7800517954000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000000000000B9A090077D3890038BC4E0010AE290015AE2C0017B3
      2F000898110081A9440000000000000000000000000000000000000000000000
      000000000000A2A7E6002337D8005183FF004877FF004372FF00406EFF003D6F
      FF00243FE0004375FF003364FF002A5BFF002657FF001D4FF900143AD1000215
      9D00000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000D2813700FFCB9200FFC78F00FFCA9500FFCC
      9700FFCC9600F5B98100C78F7400000000000000000049B4DE0095FEFF008FF5
      F70064CBDC0053B8CF004EB2CC0053B5CE0068C9DB0082DEE90099F3F600B1FF
      FF00B4FFFF00B6FFFF0060E69B0052EE7E005DFD92008E9F9400D1C6CC00B5B1
      A70076767B00206F570000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      00000000000000000000000000000A99060079D48B0035BB4C0011AF2A0015AE
      2D0017B22F0008981100697B2B00000000000000000000000000000000000000
      0000000000004444D4004D76F8005280FF004B79FF004877FF004374FF002342
      E5002828BA00121EC7004274FF003062FF002859FF002353FE001B4BF3000C23
      BF00084284000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      00000000000000000000C4772E00FFBB7700FFC38300FFC58800FFC98F00F4B5
      7800FFCA8F00FFCC8E00B8724C00000000000000000026A5D500AFFFFF009FFF
      FF009FFFFF00A1FFFF0091F8FA007EE2EB006ACDDE0054B6CE004DAECA004FAF
      CB0065C4D70080DBE70096EFEF0044D97B00A7B7AC00FFFDFF00FFFFF9007786
      D2001730D3000813B30000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000899060077D58B0035BB4C0011AF
      2B0016B02D0017B4320013850700000000000000000000000000000000000000
      0000000000001018CA006091FF005280FF004D7BFF00497AFF003C69FA001F1F
      C000000000006F538B001320CC004071FF002D5DFF002455FE001F4FFC001847
      E700030BB3000000000000000000000000000000000000000000000000000000
      000000000000F3BD7E0000000000000000000000000000000000000000000000
      000000000000CE9A6000EC9A4400FFB56700FFBA7200FFC38100DF914B00A94B
      1000FEC58D00FFC98700B967300000000000000000000AA3D30086F4F900A2FF
      FF00ADFFFF00A8FFFF00A5FFFF00A2FFFF00A1FFFF00A4FFFF0097FAFC0082E3
      EC006CCCDD0054B5CD0044A5C300A8FCEF00CBC6D100FFFFF1007890F1003565
      F9003354E3001625B900030D8600000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000000000000000000000000000000000000899070079D48C0034BA
      4C0011AF2B0018B4340020800500000000000000000000000000000000000000
      00008C8CE8003750E4005E8EFF005482FF00507EFF004B7EFF000D19CB000000
      000000000000000000007B5493001628D1003B6DFF002859FF002051FF001B4A
      F800133AD3000212A30000000000000000000000000000000000000000000000
      000000000000D7852100E6AC6100000000000000000000000000000000000000
      0000CD975400E7903300FFAF5900FFAC5700FFB36200FBAF6300C1793E000000
      0000BF692200FFCF8A00B75F20000000000000000000A1E1F70067CCF30033B7
      EA0006A5DB002EBDE20055D4EB007BECF700A3FFFF00ABFFFF00A8FFFF00A9FF
      FF00A9FFFF00ABFFFF00A3FFFF00B7FFFF004CB6E9008F9BD2003666FC004B7A
      FF00304FDC001522B5000A0C9500000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000069C0C0074D5
      890054CA6C00069A10009BB37600000000000000000000000000000000000000
      00003E3EDA006893FC006491FF005885FF005281FF003252ED004C369E000000
      0000000000000000000000000000654391001D35DA003367FF002454FF001C4E
      FD001948F2000B24C3003A439A00000000000000000000000000000000000000
      000000000000F8D5AA00DC7D0800DC821300E1A25300E6B27400DF9F5600D27B
      1700F6983800FFA84B00FFA64B00FFAA5000FFAC5400C16B1F00000000000000
      0000ECC6AE00C87E4F00E5C3AF00000000000000000000000000000000000000
      0000000000000000000043A5F7002AA4F20010A0EB0006A7DE002FBFE30055D5
      ED007CEEF700A5FFFF00B5FFFF0090F6FC00147DCA002745EB00395FEF003D65
      EE00253DCC000207A30000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000000000000000000000000000000000000000000000000000209F
      2000249E2400A6DBA60000000000000000000000000000000000000000000000
      00009B9BF2001C26D200719EFF006A9AFF005485FF000D0ABD00000000000000
      000000000000000000000000000000000000391F88002341E5002C60FF002053
      FF001C52FF001032E1000F0FB600000000000000000000000000000000000000
      00000000000000000000F3BC7100EF8B1500FF982B00FB942700FF9A3100FF9F
      3800FFA03B00FFA13F00FFA84C00FDAA5500C66E1C0000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      00000000000000000000000000000000000000000000000000000000000098AB
      8E0064AFB20031A9CB0010ACD8001DB6DE0082CCE50000000000172FD4000D19
      AE000303A1000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000ABABF4002727D6001F2CD5002637DA0084507B00000000000000
      000000000000000000000000000000000000000000003030C3002246EB00102A
      DC000D0FBE006767D60000000000000000000000000000000000000000000000
      0000000000000000000000000000F4C78900DC811100FB983200FFA94A00FFA9
      4E00FFAF5800FFB15E00E5903A00C87C33000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000006660CB0000000000000000000000
      00000000000000000000000000000000000000000000000000003636C4009292
      E000000000000000000000000000000000000000000000000000000000000000
      00000000000000000000000000000000000000000000DDAA6D00D2852600D27B
      1500D07A1B00CD894400E6C4A400000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000000000000000000000000000007500000073000000700000006D
      00000000000000000000000000000000000000000000000000004044C60099AA
      F200000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000C56024000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000000000000000000000000000000000000000000000000000C766
      2D00000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000818181008584
      8400848383008381810082808000807F7F007F7E7E007E7C7C007C7B7B007B7A
      7A007A7878007977770077767600ACABAB000079000041DA74003DD670000070
      0000A4A2A2006F6F6F000000000000000000000000003839C6001D4FFF000C1F
      C7006E6EB900C0BFC1006060B400081DCF000512BC004C4EBB00A6A6A6007B7A
      7A007A787800797777007776760076747400757373007472720072707000716F
      6F00706D6D006F6F6F0000000000000000000000000000000000000000000000
      000000000000000000000000000000000000000000000000000000000000C45C
      0A00BB4702000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000000000000000000000000000000000000000000000000000C460
      2700BE5F12000000000000000000000000000000000000000000000000000000
      000000000000000000000000000000000000000000000000000087858500FFE5
      E500FFE6E600FFE7E700FFE7E700FFE7E700A9999900FFE6E600FFE6E600FFE5
      E500FFEDED00FFEDED00FFEBEB00CBBEBE00007C000045DE780041DA74000073
      0000FEE2E200A3A3A3000000000000000000000000003B3BC0002255FF002662
      FF000B1BC4002A39B9000D27D2001857FF001456FF00071ED100FFF3F300FFE5
      E500FFE3E300FFE2E200FFE0E000A9939300FFDCDC00FFD9D900FFD7D700FFD4
      D400FDD1D1006E6E6E0000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000C7641500DF91
      3D00BA4D09000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000000000000000000000000000000000000000000000000000C460
      2700C3813700BD5F140000000000000000000000000000000000000000000000
      000000000000000000000000000000000000000000000000000088878700FFE9
      E900FFEAEA00FFEBEB00FFEBEB00FFEBEB00A99C9C00FFEAEA00FFE9E900FFE8
      E800FFF0F000008500000084000000810000007F000049E27C0045DE78000078
      00000075000000720000006F000000000000000000005557CA001E44EC002C68
      FF002760FF001A48F5001F58FF001B54FF001140F8003B3AB800FFF3F300FFE8
      E800FFE7E700FFE5E500FFE3E300A9959500FFDFDF00FFDCDC00FFDADA00FFD7
      D700FDD3D300706F6F0000000000000000000000000000000000000000000000
      00000000000000000000000000000000000000000000CB681600EB9E4D00D188
      3B00B84B08000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000000000000000000000000000000000000000000000000000C25E
      2700C27E3600BE814000C0621400000000000000000000000000000000000000
      000000000000000000000000000000000000000000000000000089888800FFED
      ED00FFEEEE00FFEFEF00FFEFEF00FFEFEF00A99E9E00FFEEEE00FFEDED00FFEC
      EC00FFF2F2000089000055EE880052EB85004FE882004CE57F004BE47E0047E0
      7A0043DC76003FD8720000720000000000000000000000000000595BBB00121C
      BC002C6DFF002459FF002258FF001235E0005755AC00FFF7F700FFEDED00FFEC
      EC00FFEAEA00FFE8E800FFE6E600A9979700FFE2E200FFDFDF00FFDCDC00FFDA
      DA00FDD5D5007170700000000000000000000000000000000000000000000000
      0000000000000000000000000000F5CBAC00CF6E1A00EDA45800E89C4B00C67E
      3800BA510D000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000000000000000000000000000000000000000000000000000BB59
      2300D3883B00CD894200C1864800C3661600F7CEAF0000000000000000000000
      00000000000000000000000000000000000000000000000000008A898900FFF0
      F000FFF2F200FFF2F200FFF3F300FFF3F300A9A1A100FFF2F200FFF1F100FFEF
      EF00FFF5F500008B000058F18B0055EE880053EC860050E983004DE680004BE4
      7E0047E07A0043DC760000760000000000000000000000000000C6C6C6006563
      BA002C66FA002C6FFF00275EFF000D20C500D7D3D300FFF2F200FFF1F100FFEF
      EF00FFEEEE00FFECEC00FFE9E900A9999900FFE4E400FFE2E200FFDFDF00FFDC
      DC00FDD8D8007272720000000000000000000000000000000000000000000000
      00000000000000000000C1500300D2711A00F0AB6500E89F5400EA9C4D00B87A
      3700B44A0700B35B1000B1570F00AF540C00AD510900AB4E0800A84B0500A749
      0300A8480100A74D1C0000000000000000000000000000000000AA471000AB44
      0100AC450200AC470300AE490500AF4B0700B04D0900B24F0900B2510B00B04E
      0A00E4964500EB9D4D00D18E4B00C08B5200C5691900F8CCAB00000000000000
      00000000000000000000000000000000000000000000000000008B8B8B00FFF4
      F400FFF5F500FFF6F600FFF7F700FFF7F700A9A4A400FFF6F600FFF5F500FFF3
      F300FFF7F700008E0000008B0000008900000087000053EC860050E983000080
      0000007E0000007C0000007900000000000000000000000000007C8CD400121B
      BC003474FB002459EA00214BF200296AFF001016B900FFFBFB00FFF5F500FFF3
      F300FFF1F100FFEFEF00FFEDED00A99B9B00FFE7E700FFE4E400FFE1E100FFDE
      DE00FDDADA007373730000000000000000000000000000000000000000000000
      000000000000CA680C00D5772000F4B47200ECA65F00E7A05500E89C5000BD7E
      3D00BF7E3A00BD7B3400BB772E00BB732800BB6F2000B96B1A00B7671300B763
      0D00B5620500A8501C0000000000000000000000000000000000AD4F1800BD69
      0900B8640C00B9691300B96B1A00B96F2000BA722700BA762D00BB783300B97B
      3800C2813F00E99D5000EDA25800D4965600C0905D00C76E1C00F9CCA7000000
      00000000000000000000000000000000000000000000000000008A8B8C008797
      A5008898A6008899A600899AA600899AA600899AA6008899A6008898A6008796
      A400B7BFC800B7BFC700B6BEC700B6BCC600008A000057F08A0053EC86000082
      0000B4B6C100ABABAB00000000000000000000000000000000001C24BA00326B
      F9003573FB002E39B2003741B3002558FF002460FF003C3DB200BBC4CC008796
      A4008795A4008794A2008692A2008590A000858E9E00848C9D00838A9C008388
      9A00828598007474740000000000000000000000000000000000000000000000
      0000DE6E0900DA7D2700F8BD8000F0AE6B00EBA66200E9A15900E79E5200E99B
      4D00E8964600E4933F00E28D3800E1882F00DD832800DB7E2100D8791A00D774
      1100CB6F0B00A74C1A0000000000000000000000000000000000A94B1500DC83
      2100D5720F00D7791A00DB7E2100DD832800E1882F00E38E3800E5913F00E896
      4600E89B4C00E79E5200EBA25A00F1AB6300D79D6100C1946500CA712000FAC8
      A000000000000000000000000000000000000000000000000000848A8E0052B8
      FF0055BBFF0056BCFF0058BEFF0058BEFF003A7DA90056BCFF0054BAFF0051B7
      FF004FB5FF004CB2FF0049AFFF0082AACB00008D00005AF38D0057F08A000086
      000081C0FE007474750000000000000000000000000000000000121BBA00326A
      F800192FD0008EAFF500AFE0FF002931B4002661FF000A1DC500AFDFFF0051B7
      FF004FB5FF004CB2FF0049AFFF002E72A90043A9FF0040A6FF003DA3FF003AA0
      FF00379CFD00747475000000000000000000000000000000000000000000E67F
      2100E0852E00FCC69000F4B67600EFAE6D00EDA96500EAA45F00E89F5600E59B
      4F00E3964800E2914100DE8E3A00DC883300DB832B00D77E2400D57A1D00D575
      1700D0720F00A34B180000000000000000000000000000000000AA4A1400DF8E
      3900D2731300D5791D00D77E2400DB832B00DC893400DE8C3A00E2914100E396
      4800E59B4F00E89F5600EAA45D00EFAA6600F5B36F00DBA56B00C0996E00CE78
      2600FAC79A000000000000000000000000000000000000000000858B8F0055BB
      FF0057BDFF0059BFFF005BC1FF005BC1FF003C7FA90059BFFF0056BCFF0054BA
      FF0051B7FF004EB4FF004AB0FF0082ABCB00008F0000008E0000008B00000089
      000081C1FE007576760000000000000000000000000000000000C1C3CB00404F
      CD005E6FDD00A5DCFF005BC1FF00AFE1FF00333BBF00778CE0008DD2FF0054BA
      FF0051B7FF004EB4FF004AB0FF002F73A90044AAFF0041A7FF003EA4FF003BA1
      FF00389DFD007576760000000000000000000000000000000000C0570A00EA95
      4000FFDBB100F9C79500F3B67600F1B17000EEAC6900ECA76200E9A25B00E79E
      5300E4994C00E2944500E08F3E00DD8B3700DB863000D9812900D67C2100D577
      1A00D6781500A4470D0000000000000000000000000000000000A8491200E39A
      4D00D4741400D67C2100D9812800DB863000DD8A3700E08F3E00E2944500E499
      4C00E79D5300E9A25A00ECA76200EEAC6A00F3B37100F9BA7A00E1AC7800C29E
      7A00CE7F3400F5C3950000000000000000000000000000000000878C900057BD
      FF005AC0FF005CC2FF005EC4FF005FC5FF003E81A9005BC1FF0058BEFF0055BB
      FF0052B8FF004FB5FF004BB1FF0083ABCB008FCDFF008ECBFF008BC8FF0083C4
      FF0082C1FE007677770000000000000000000000000000000000888D91009DD9
      FF0096D7FF005CC2FF005EC4FF005FC5FF0091B7CE0090D5FF0058BEFF0055BB
      FF0052B8FF004FB5FF004BB1FF003073A90045ABFF0042A8FF003EA4FF003BA1
      FF00399DFD007677770000000000000000000000000000000000C4570800EC99
      4800FFE7C900FCD7B300F5B87A00F2B47500F0AF6D00EDAA6600EBA55F00E8A0
      5800E69C5000E4974A00E1924200DF8D3B00DC893400DA842D00D87F2600D57A
      1E00DB7D1900A6460B0000000000000000000000000000000000AA460F00E5A1
      5700D77B2000D87F2400DA842D00DC883400DF8D3B00E1924200E4974900E69B
      5000E8A05700EBA55F00EDAA6600F0AF6D00F2B37400F5B97D00FFC78C00CDA9
      8400D0843800F4BE8D0000000000000000000000000000000000888D910058BE
      FF005BC1FF005EC4FF0061C7FF0062C8FF004083A9005DC3FF005AC0FF0056BC
      FF0053B9FF004FB5FF004CB2FF003074A90045ABFF0042A8FF003FA5FF003CA2
      FF00399EFD007778780000000000000000000000000000000000888D910058BE
      FF005BC1FF005EC4FF0061C7FF0062C8FF004083A9005DC3FF005AC0FF0056BC
      FF0053B9FF004FB5FF004CB2FF003074A90045ABFF0042A8FF003FA5FF003CA2
      FF00399EFD00777878000000000000000000000000000000000000000000EC8B
      3200E58D3600FFE2C000FAD7B300F4B67600F1B27100EFAD6A00ECA86300EAA3
      5C00E79D5400E5994A00E3944400E08E3B00DE893400DB862E00D9812700D77D
      2200DA7F1D00AC4E140000000000000000000000000000000000A8480F00E6A3
      5A00DA893300D97F2400DB842D00DE893400E08E3B00E3944400E5994900E79C
      5000EAA25900ECA86200EFAD6A00F1B17100F3B67A00F8BE8400FFD19F00CE7E
      2D00FCC392000000000000000000000000000000000000000000898E920058BE
      FF005BC1FF005FC5FF0062C8FF0063C9FF004084A9005DC3FF005AC0FF0056BC
      FF0053B9FF0050B6FF004CB2FF003074A90046ACFF0042A8FF003FA5FF003CA2
      FF00399EFD007979790000000000000000000000000000000000898E920058BE
      FF005BC1FF005FC5FF0062C8FF0063C9FF004084A9005DC3FF005AC0FF0056BC
      FF0053B9FF0050B6FF004CB2FF003074A90046ACFF0042A8FF003FA5FF003CA2
      FF00399EFD007979790000000000000000000000000000000000000000000000
      0000E7883600E2892E00FFE0BD00FBD6B200F3B57500F0B06E00EEAB6700EBA6
      5E00EBAC6A00EBAE7000E9A86800E7A35F00E39E5900E1995100E0944900DB8D
      3B00DC812000AE51150000000000000000000000000000000000AA490F00E8A7
      6000E29F5D00DF954B00E39B5400E5A15D00E6A66400E8AA6A00EAAD6F00ECB3
      7700EEB87E00EFB27600F0B06D00F2B47700F7BC8100FFCC9900E28A3300F9C5
      98000000000000000000000000000000000000000000000000008A8F930057BD
      FF005BC1FF005EC4FF0060C6FF0061C7FF003F83A9005CC2FF0059BFFF0056BC
      FF0053B9FF004FB5FF004CB2FF003074A90045ABFF0042A8FF003FA5FF003BA1
      FF00399EFD007A7A7A00000000000000000000000000000000008A8F930057BD
      FF005BC1FF005EC4FF0060C6FF0061C7FF003F83A9005CC2FF0059BFFF0056BC
      FF0053B9FF004FB5FF004CB2FF003074A90045ABFF0042A8FF003FA5FF003BA1
      FF00399EFD007A7A7A0000000000000000000000000000000000000000000000
      000000000000EC964900E0842A00FFDEBA00FAD7B200F2B27100EFAD6900EEA9
      6200F5CA9D00F7CCA000F5C69700F3C28E00EFBD8700EDB87C00ECB27400EBAF
      6E00E6953C00B152190000000000000000000000000000000000AF4E1200E9A5
      5600EBB07100EDB37800EEB97E00F0BD8700F0BF8B00F2C39200F4C79800F6C9
      9A00F6D1AB00F3BC8700F1B17200F6BA7F00FFCB9600DF842D00F8C69F000000
      0000000000000000000000000000000000000000000000000000939494009AA3
      A9009AA3A9009AA4A9009AA4A9009AA4A9009AA4A9009AA3A9009AA3A9009AA3
      A9009AA2A90099A2A90099A0A800989EA500989CA3009899A00098969E009894
      9C00969099007B7B7B0000000000000000000000000000000000939494009AA3
      A9009AA3A9009AA4A9009AA4A9009AA4A9009AA4A9009AA3A9009AA3A9009AA3
      A9009AA2A90099A2A90099A0A800989EA500989CA3009899A00098969E009894
      9C00969099007B7B7B0000000000000000000000000000000000000000000000
      00000000000000000000CF5C0800DD7F2400FFDCB600F9D5B000F5BE8800EEAB
      6800BE530D00C4601600C35E1300C25C1100C05A1000BE570D00BC540B00BB53
      0900BB550B00AF541B0000000000000000000000000000000000AD511600BB52
      0900BB530900BC540900BE570D00C05A1000C25C1100C35E1300C4601600C35C
      1000F4C18D00F1B67A00F5B87A00FEC99100DC812B00F8CAA400000000000000
      000000000000000000000000000000000000000000000000000095959500FFFF
      FF00FFFFFF00FFFFFF00FFFFFF00FFFFFF00A9A9A900FFFFFF00FFFFFF00FFFF
      FF00FFFFFF00FFFFFF00FFFCFC00A9A4A400FFF4F400FFF1F100FFEDED00FFE9
      E900FDE4E4007C7C7C000000000000000000000000000000000095959500FFFF
      FF00FFFFFF00FFFFFF00FFFFFF00FFFFFF00A9A9A900FFFFFF00FFFFFF00FFFF
      FF00FFFFFF00FFFFFF00FFFCFC00A9A4A400FFF4F400FFF1F100FFEDED00FFE9
      E900FDE4E4007C7C7C0000000000000000000000000000000000000000000000
      0000000000000000000000000000F7C8A400DA7B2300FFD9B200FCDEBF00F1AF
      6B00BC5114000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000000000000000000000000000000000000000000000000000BD58
      2100F6C59200F5B77800FCC68E00DA7E2900F8CBA80000000000000000000000
      000000000000000000000000000000000000000000000000000096969600FFFF
      FF00FFFFFF00FFFFFF00FFFFFF00FFFFFF00A9A9A900FFFFFF00FFFFFF00FFFF
      FF00FFFFFF00FFFEFE00FFFCFC00A9A4A400FFF4F400FFF0F000FFECEC00FFE8
      E800FDE3E3007D7D7D000000000000000000000000000000000096969600FFFF
      FF00FFFFFF00FFFFFF00FFFFFF00FFFFFF00A9A9A900FFFFFF00FFFFFF00FFFF
      FF00FFFFFF00FFFEFE00FFFCFC00A9A4A400FFF4F400FFF0F000FFECEC00FFE8
      E800FDE3E3007D7D7D0000000000000000000000000000000000000000000000
      000000000000000000000000000000000000F7CBA900D7792000FFDDB600F6B9
      7800C45817000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000000000000000000000000000000000000000000000000000C461
      2700F9C48D00FCC58A00D77B2400F7CDAC000000000000000000000000000000
      000000000000000000000000000000000000000000000000000098989800FFFF
      FF00FFFFFF00FFFFFF00FFFFFF00FFFFFF00A9A9A900FFFFFF00FFFFFF00FFFF
      FF00FFFFFF00FFFEFE00FFFBFB00A9A4A400FFF3F300FFF0F000FFECEC00FFE8
      E800FDE3E3007F7E7E000000000000000000000000000000000098989800FFFF
      FF00FFFFFF00FFFFFF00FFFFFF00FFFFFF00A9A9A900FFFFFF00FFFFFF00FFFF
      FF00FFFFFF00FFFEFE00FFFBFB00A9A4A400FFF3F300FFF0F000FFECEC00FFE8
      E800FDE3E3007F7E7E0000000000000000000000000000000000000000000000
      00000000000000000000000000000000000000000000F8CEAF00D6772300FEC5
      8900C75A17000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000000000000000000000000000000000000000000000000000C662
      2700FFC88D00D577250000000000000000000000000000000000000000000000
      000000000000000000000000000000000000000000000000000099999900FFFF
      FF00FFFFFF00FFFFFF00FFFFFF00FFFFFF00A9A9A900FFFFFF00FFFFFF00FFFF
      FF00FFFEFE00FFFDFD00FFF9F900A9A3A300FFF2F200FFEEEE00FFEBEB00FFE7
      E700FDE2E200808080000000000000000000000000000000000099999900FFFF
      FF00FFFFFF00FFFFFF00FFFFFF00FFFFFF00A9A9A900FFFFFF00FFFFFF00FFFF
      FF00FFFEFE00FFFDFD00FFF9F900A9A3A300FFF2F200FFEEEE00FFEBEB00FFE7
      E700FDE2E2008080800000000000000000000000000000000000000000000000
      000000000000000000000000000000000000000000000000000000000000D574
      1D00C35006000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000000000000000000000000000000000000000000000000000C864
      2700D67A25000000000000000000000000000000000000000000000000000000
      00000000000000000000000000000000000000000000000000009A9A9A00FDFD
      FD00FDFDFD00FDFDFD00FDFDFD00FDFDFD00A8A8A800FDFDFD00FDFDFD00FDFD
      FD00FDFCFC00FDFAFA00FDF6F600A8A1A100FDEFEF00FDECEC00FDE8E800FDE5
      E500FCE0E00081818100000000000000000000000000000000009A9A9A00FDFD
      FD00FDFDFD00FDFDFD00FDFDFD00FDFDFD00A8A8A800FDFDFD00FDFDFD00FDFD
      FD00FDFCFC00FDFAFA00FDF6F600A8A1A100FDEFEF00FDECEC00FDE8E800FDE5
      E500FCE0E0008181810000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000CB6421000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000000000000000000000000000000000000000000000000000CB69
      2C00000000000000000000000000000000000000000000000000000000000000
      00000000000000000000000000000000000000000000000000009B9B9B009999
      9900989898009797970095959500949494009393930091919100909090008F8F
      8F008D8D8D008C8C8C008B8B8B00898989008888880087878700858585008484
      84008383830088888800000000000000000000000000000000009B9B9B009999
      9900989898009797970095959500949494009393930091919100909090008F8F
      8F008D8D8D008C8C8C008B8B8B00898989008888880087878700858585008484
      8400838383008888880000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000000000000000000000000000424D3E000000000000003E000000
      2800000060000000600000000100010000000000800400000000000000000000
      000000000000000000000000FFFFFF0000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      00000000000000000000000000000000FFFFFFFFFFFFFFFFFFFFFFFFFF3FFFFF
      00FFFFFFFFFF00FFFE1FFFFC003FFFFFFFFF00FFF80FFFF8001FFFFFFFFF00FF
      F807FFF0000FFFFFFFFF00FFF803FFE00007FFFFFFFF00FFFC01FFC00003FFFF
      FFFF00FFFE00FFC00003FFFFFFFF00FFFF007F800001800001800001FF803F80
      0001800001800001FFC01F800001800001800001FFE00F800001800001800001
      FFE01F800001800001800001FFC03F800001800001800001FF807F8000018000
      01800001FF00FF800001800001800001FE01FFC00003FFFFFFFF00FFFC03FFC0
      0003FFFFFFFF00FFF807FFE00007FFFFFFFF00FFF80FFFF0000FFFFFFFFF00FF
      FC1FFFF8001FFFFFFFFF00FFFE3FFFFC003FFFFFFFFF00FFFF7FFFFF00FFFFFF
      FFFF00FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFF
      FFFFE3FF3FFF81FFFE3FFFFFFFFFC0FE0FFF007FFC07FFFFFFFF807C07FE003F
      F801FFFF1FFF803803DC003FF8003FFE0FFF800001880F9FF0000FFC07FFC000
      01801FDFF00001F803FFC00001803FFFE00001F001FFC00007807FFFE00003E0
      00FFE0000F807FFFE00007C0007FF8001FC01FC7C0000780403FFC003FC01803
      C0000F80E01FFE003FC01003C0001F81F00FFC001FE3F80380000FC3F807FC00
      1FFFFE01800007FFFC03F8000FFFFE01800003FFFE01F80007FFFC01800003FF
      FF01F80807FBF801800001FFFF81F01C03F9F011800001FFFFC1F01E01F80031
      FC0003FFFFE3F03F01FC007FFFE047FFFFFFF83F83FE00FFFFFFFFFFFFFFFF7F
      CFFF81FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFF
      FFFFFFFF0FCFFFFFFFF7FFFFEFFFC00003800003FFE7FFFFE7FFC00003800003
      FFC7FFFFE3FFC00001800003FF87FFFFE1FFC00001C00003FE07FFFFE07FC000
      01C00003FC0003C0003FC00001C00003F80003C0001FC00003C00003F00003C0
      000FC00003C00003E00003C00007C00003C00003C00003C00003C00003C00003
      C00003C00003C00003C00003E00003C00007C00003C00003F00003C0000FC000
      03C00003F80003C0001FC00003C00003FC0003C0003FC00003C00003FE07FFFF
      E07FC00003C00003FF07FFFFE0FFC00003C00003FF87FFFFE3FFC00003C00003
      FFE7FFFFE7FFC00003C00003FFF7FFFFEFFFC00003C00003FFFFFFFFFFFFFFFF
      FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFF00000000000000000000000000000000
      000000000000}
  end
  object DSCustomers: TDataSource
    AutoEdit = False
    DataSet = DatasetCustomers
    Left = 408
    Top = 96
  end
  object ImageList2: TImageList
    Left = 440
    Top = 152
    Bitmap = {
      494C010102000500640010001000FFFFFFFFFF10FFFFFFFFFFFFFFFF424D3600
      0000000000003600000028000000400000001000000001002000000000000010
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000467A70000F602A0009660E0009650D000E5C260045756A000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000008487D400383BBA00141BAE001419AC003839B1008585CD000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000002711100088800000F8D0000138800001F7E000032720000266D00000762
      0C00000000000000000000000000000000000000000000000000000000000000
      00001323C3000020D8000023E0000020D9000019CC000011BD00000BB0001416
      A700000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000182
      080004A00800009D000000A30D00009A000000970000009400001E7E0000396B
      00000E680300000000000000000000000000000000000000000000000000041F
      D500002FFF00002AFB00002DF800002BF2000029EE00001FE5000012CA00000D
      B5000408A4000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000077E1A000BA8
      170001A10D0038BF5400DCF3E9007ADD9B00009D030000990000009800001285
      00003A6C000005640C00000000000000000000000000000000001831D5000035
      FF0097A9E8006888F4000034FF00002DFF000033FA0095B1FD004570FB000019
      D400000DB6001315A70000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000047837D0024A82F0008A9
      1B002BB43E00F6F0F500FCF6FA00FFFFFF007DDE9C00009E0700009B02000099
      0000217D0000266E0000447569000000000000000000818BE400254EF8000034
      FF00CACDDC00F5EFDE00B1C0EF00063DFF00B1C4FA00FFFFFB00FFFFFF006187
      FD000012CA00000CB2008484CD00000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000000000000000000000000000000000000F77370039BF4F0020AA
      2A00ECE6EA00F5EEF400FFF7FF00FFFBFF00FFFFFF007DDD9C00009F0900009B
      020002940000367100000C5F270000000000000000003950DF003D6AFF00083C
      FE0097A3E200F4F1E100F6F3EA00E4E8F300FFFFF800FFFFFF00F3F7FF00446D
      FD000022E7000012BE00373AB600000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      00000000000000000000000000000000000000000000108A28003BBE5000AAC9
      A700FAEAF900D1E1D1001EAF3300DAEFDD00FFFFFF00FFFFFF007BDD9B00009E
      0700009A0000297C000008690C0000000000000000002241E2004B76FF002255
      FF000D41FE005C7AF000EFEDEB00F6F6F300FFFFFB00CDDAFE00174AFF00002A
      FC00002CF3000019CB00121AB100000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      00000000000000000000000000000000000000000000138C2A005DD27C0017A3
      25008EBF880011AB24001BB940000BAD2700DBEFDF00FFFFFF00FFFFFF007BDB
      9900009E02001B860000086B0C0000000000000000002444E6006489FF002E5F
      FF003364FF000639FC00DADCED00FBF9F300FFFFFB00ADC0FE000033FF000236
      FF000030FA000020DA00121CB400000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000000000000000000000000000000000000F7C3B0077DC95002CC5
      5A001FBB450029C4560026C04F0022BB45000BAC2600DAEEDD00FFFCFF00FFFD
      FF0078DB9600138C00000D662A0000000000000000003C57E80083A2FF003D6C
      FF003C6CFF00526DE900FFFBE900B2BFF300D4DAF900FFFFFF006D8FFF000033
      FF000032FF000023E2003940C100000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000000000000000000000000000000000004A8C870053C66C0061D8
      89002ECA62002FC961002CC5590027C04D0020B9420009AA2200DAEDDD00FFF9
      FF00CAECD7000E8E0800477F730000000000000000008492F100688AFA007096
      FF002958F700BABEDE00F5F3E7001543F7000E41FD00E7ECFB00FFFFFF002958
      FE000035FF000021DA00878CDA00000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000000000000000000000000000000000000000000010902A0083E5
      A7004CD379002ECB63002FC9600029C3540022BB46001CB53A000BA81F007ACC
      850011AD24000272110000000000000000000000000000000000294DEF0097B6
      FF004C75F9005B6CD800546BE1003365FF002D5FFF001745FC00C7D1FA004C73
      FF000135FF001629CA0000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      00000000000000000000000000000000000000000000000000000000000022A3
      300081E3A6005ED8880031CA630027C3530021BC47001DB63B0016B02E000FA9
      2000018709000000000000000000000000000000000000000000000000002F53
      F30096B3FF006F95FF003966FA00396AFF002F60FF002153FE00083CFF000C40
      FF000625DB000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000011912B0056CA730077E09C0059D37E0043C8660036C154001EAA2F000781
      1800000000000000000000000000000000000000000000000000000000000000
      0000294CF2006688FA0088A9FF006A8FFF004F7AFF003E6DFF00204CF6001D37
      DC00000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000004F91890011813D0015902F00118C2B00117D38004D8C82000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000008C99F4003F5DEC002B4CEC002A49E8004058E4008D98EC000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      0000000000000000000000000000000000000000000000000000000000000000
      000000000000000000000000000000000000424D3E000000000000003E000000
      2800000040000000100000000100010000000000800000000000000000000000
      000000000000000000000000FFFFFF00FFFFFFFF00000000F81FF81F00000000
      F00FF00F00000000E007E00700000000C003C003000000008001800100000000
      8001800100000000800180010000000080018001000000008001800100000000
      8001800100000000C003C00300000000E007E00700000000F00FF00F00000000
      F81FF81F00000000FFFFFFFF0000000000000000000000000000000000000000
      000000000000}
  end
  object QueryInvoice: TIBQuery
    Database = FrmMain.database
    Transaction = FrmMain.IBTransaction1
    BufferChunks = 1000
    CachedUpdates = False
    DataSource = DSCustomers
    ParamCheck = True
    SQL.Strings = (
      'SELECT INVOICE.*, PAYMENT_METHOD.DESCRIPTION AS PAYMETHOD '
      
        'FROM INVOICE LEFT OUTER JOIN PAYMENT_METHOD ON INVOICE.PAYMETH_I' +
        'D = PAYMENT_METHOD.METHOD_ID'
      'WHERE CUST_ID = :CUST_ID'
      'ORDER BY '
      'INVCODE DESC')
    Left = 452
    Top = 328
    ParamData = <
      item
        DataType = ftInteger
        Name = 'CUST_ID'
        ParamType = ptUnknown
        Size = 4
      end>
    object QueryInvoiceINVOICE_ID: TIntegerField
      FieldName = 'INVOICE_ID'
      Origin = '"INVOICE"."INVOICE_ID"'
      ProviderFlags = [pfInUpdate, pfInWhere, pfInKey]
      Required = True
    end
    object QueryInvoiceINVCODE: TIBStringField
      FieldName = 'INVCODE'
      Origin = '"INVOICE"."INVCODE"'
      Required = True
      Size = 15
    end
    object QueryInvoiceCUST_ID: TIntegerField
      FieldName = 'CUST_ID'
      Origin = '"INVOICE"."CUST_ID"'
    end
    object QueryInvoiceINVTYPE: TIBStringField
      FieldName = 'INVTYPE'
      Origin = '"INVOICE"."INVTYPE"'
      Size = 6
    end
    object QueryInvoiceINVDATE: TDateField
      FieldName = 'INVDATE'
      Origin = '"INVOICE"."INVDATE"'
    end
    object QueryInvoicePRINTED: TSmallintField
      FieldName = 'PRINTED'
      Origin = '"INVOICE"."PRINTED"'
    end
    object QueryInvoiceDELIVERYDATE: TDateField
      FieldName = 'DELIVERYDATE'
      Origin = '"INVOICE"."DELIVERYDATE"'
    end
    object QueryInvoiceDISTRAIM_ID: TIntegerField
      FieldName = 'DISTRAIM_ID'
      Origin = '"INVOICE"."DISTRAIM_ID"'
    end
    object QueryInvoiceDELMETHOD_ID: TIntegerField
      FieldName = 'DELMETHOD_ID'
      Origin = '"INVOICE"."DELMETHOD_ID"'
    end
    object QueryInvoicePAYMETH_ID: TIntegerField
      FieldName = 'PAYMETH_ID'
      Origin = '"INVOICE"."PAYMETH_ID"'
    end
    object QueryInvoiceDISCOUNT: TIBBCDField
      FieldName = 'DISCOUNT'
      Origin = '"INVOICE"."DISCOUNT"'
      Precision = 18
      Size = 2
    end
    object QueryInvoicePRICE: TIBBCDField
      FieldName = 'PRICE'
      Origin = '"INVOICE"."PRICE"'
      Precision = 18
      Size = 2
    end
    object QueryInvoicePRICEWVAT: TIBBCDField
      FieldName = 'PRICEWVAT'
      Origin = '"INVOICE"."PRICEWVAT"'
      currency = True
      Precision = 18
      Size = 2
    end
    object QueryInvoiceCONV_INVOICE_ID: TIntegerField
      FieldName = 'CONV_INVOICE_ID'
      Origin = '"INVOICE"."CONV_INVOICE_ID"'
    end
    object QueryInvoiceINVTIME: TTimeField
      FieldName = 'INVTIME'
      Origin = '"INVOICE"."INVTIME"'
    end
    object QueryInvoicePAYMETHOD: TIBStringField
      FieldName = 'PAYMETHOD'
      Origin = '"PAYMENT_METHOD"."DESCRIPTION"'
      Size = 60
    end
    object QueryInvoiceADDRESS1: TIBStringField
      FieldName = 'ADDRESS1'
      Origin = '"INVOICE"."ADDRESS1"'
      Size = 50
    end
    object QueryInvoiceADDRESS2: TIBStringField
      FieldName = 'ADDRESS2'
      Origin = '"INVOICE"."ADDRESS2"'
      Size = 50
    end
    object QueryInvoiceCITY: TIBStringField
      FieldName = 'CITY'
      Origin = '"INVOICE"."CITY"'
      Size = 40
    end
    object QueryInvoicePOSTCODE: TIBStringField
      FieldName = 'POSTCODE'
      Origin = '"INVOICE"."POSTCODE"'
      Size = 10
    end
    object QueryInvoiceCOUNTRY: TIBStringField
      FieldName = 'COUNTRY'
      Origin = '"INVOICE"."COUNTRY"'
      Size = 40
    end
    object QueryInvoiceNOTES: TIBStringField
      FieldName = 'NOTES'
      Origin = '"INVOICE"."NOTES"'
      Size = 2000
    end
    object QueryInvoiceCODE: TIntegerField
      FieldName = 'CODE'
      Origin = '"INVOICE"."CODE"'
    end
  end
  object DSInvoices: TDataSource
    AutoEdit = False
    DataSet = QueryInvoice
    Left = 488
    Top = 328
  end
  object DSPayments: TDataSource
    DataSet = DatasetPayment
    Left = 488
    Top = 368
  end
  object DatasetPayment: TIBDataSet
    Database = FrmMain.database
    Transaction = FrmMain.IBTransaction1
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
      'SELECT * FROM PAYMENT'
      'WHERE CUST_ID = :CUST_ID'
      'ORDER BY '
      'PAYMENT.PAY_DATE DESC')
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
    Filtered = True
    DataSource = DSCustomers
    Left = 456
    Top = 368
    object DatasetPaymentPAYMENT_ID: TIntegerField
      FieldName = 'PAYMENT_ID'
      Origin = '"PAYMENT"."PAYMENT_ID"'
      ProviderFlags = [pfInUpdate, pfInWhere, pfInKey]
      Required = True
    end
    object DatasetPaymentCUST_ID: TIntegerField
      FieldName = 'CUST_ID'
      Origin = '"PAYMENT"."CUST_ID"'
    end
    object DatasetPaymentPAY_DATE: TDateField
      FieldName = 'PAY_DATE'
      Origin = '"PAYMENT"."PAY_DATE"'
    end
    object DatasetPaymentVALUE: TIBBCDField
      FieldName = 'VALUE'
      Origin = '"PAYMENT"."VALUE"'
      currency = True
      Precision = 18
      Size = 2
    end
    object DatasetPaymentNOTES: TMemoField
      FieldName = 'NOTES'
      Origin = '"PAYMENT"."NOTES"'
      ProviderFlags = [pfInUpdate]
      BlobType = ftMemo
      Size = 8
    end
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
  object QueryOccupation: TIBQuery
    Database = FrmMain.database
    Transaction = FrmMain.IBTransaction1
    BufferChunks = 1000
    CachedUpdates = False
    ParamCheck = True
    SQL.Strings = (
      'SELECT DISTINCT OCCUPATION  FROM CUSTOMER')
    Left = 256
    Top = 392
    object QueryOccupationOCCUPATION: TIBStringField
      FieldName = 'OCCUPATION'
      Origin = '"CUSTOMER"."OCCUPATION"'
      Size = 120
    end
  end
  object DSOcupation: TDataSource
    AutoEdit = False
    DataSet = QueryOccupation
    Left = 296
    Top = 392
  end
  object QueryTaxOffice: TIBQuery
    Database = FrmMain.database
    Transaction = FrmMain.IBTransaction1
    BufferChunks = 1000
    CachedUpdates = False
    ParamCheck = True
    SQL.Strings = (
      'SELECT DISTINCT TAXOFFICE FROM CUSTOMER')
    Left = 408
    Top = 432
    object QueryTaxOfficeTAXOFFICE: TIBStringField
      FieldName = 'TAXOFFICE'
      Origin = '"CUSTOMER"."TAXOFFICE"'
      Size = 30
    end
  end
  object DSTaxOffice: TDataSource
    AutoEdit = False
    DataSet = QueryTaxOffice
    Left = 440
    Top = 432
  end
  object QueryCity: TIBQuery
    Database = FrmMain.database
    Transaction = FrmMain.IBTransaction1
    BufferChunks = 1000
    CachedUpdates = False
    ParamCheck = True
    SQL.Strings = (
      'SELECT DISTINCT CITY FROM CUSTOMER')
    Left = 208
    Top = 527
    object QueryCityCITY: TIBStringField
      FieldName = 'CITY'
      Origin = '"CUSTOMER"."CITY"'
      Size = 30
    end
  end
  object DSCity: TDataSource
    AutoEdit = False
    DataSet = QueryCity
    Left = 240
    Top = 527
  end
  object QueryCountry: TIBQuery
    Database = FrmMain.database
    Transaction = FrmMain.IBTransaction1
    BufferChunks = 1000
    CachedUpdates = False
    ParamCheck = True
    SQL.Strings = (
      'SELECT DISTINCT COUNTRY FROM CUSTOMER')
    Left = 296
    Top = 511
    object QueryCountryCOUNTRY: TIBStringField
      FieldName = 'COUNTRY'
      Origin = '"CUSTOMER"."COUNTRY"'
      Size = 40
    end
  end
  object DSCountry: TDataSource
    AutoEdit = False
    DataSet = QueryCountry
    Left = 328
    Top = 511
  end
  object QueryPaymentMethod: TIBQuery
    Database = FrmMain.database
    Transaction = FrmMain.IBTransaction1
    BufferChunks = 1000
    CachedUpdates = False
    DataSource = DSCustomers
    ParamCheck = True
    SQL.Strings = (
      'SELECT * FROM PAYMENT_METHOD'
      'ORDER BY'
      'DESCRIPTION')
    Left = 592
    Top = 567
    object QueryPaymentMethodMETHOD_ID: TIntegerField
      FieldName = 'METHOD_ID'
      Origin = '"PAYMENT_METHOD"."METHOD_ID"'
      ProviderFlags = [pfInUpdate, pfInWhere, pfInKey]
      Required = True
    end
    object QueryPaymentMethodDESCRIPTION: TIBStringField
      FieldName = 'DESCRIPTION'
      Origin = '"PAYMENT_METHOD"."DESCRIPTION"'
      Size = 120
    end
    object QueryPaymentMethodDUE_DAYS: TIntegerField
      FieldName = 'DUE_DAYS'
      Origin = '"PAYMENT_METHOD"."DUE_DAYS"'
    end
  end
  object DSPaymentMethod: TDataSource
    AutoEdit = False
    DataSet = QueryPaymentMethod
    Left = 624
    Top = 567
  end
  object DatasetCustomers: TIBDataSet
    Database = FrmMain.database
    Transaction = FrmMain.IBTransaction1
    AfterInsert = DatasetCustomerAfterInsert
    AfterOpen = DatasetCustomersAfterOpen
    AfterScroll = DatasetCustomerAfterScroll
    BufferChunks = 1000
    CachedUpdates = False
    DeleteSQL.Strings = (
      'delete from "CUSTOMER"'
      'where'
      '  "CUSTOMER"."CUST_ID" = :"OLD_CUST_ID"')
    InsertSQL.Strings = (
      'insert into "CUSTOMER"'
      
        '  ("CUSTOMER"."CUST_ID", "CUSTOMER"."ALT_CUSTID", "CUSTOMER"."AF' +
        'M", "CUSTOMER"."NAME", '
      
        '   "CUSTOMER"."ADDRESS1", "CUSTOMER"."ADDRESS2", "CUSTOMER"."CIT' +
        'Y", "CUSTOMER"."POSTCODE", '
      
        '   "CUSTOMER"."PHONE1", "CUSTOMER"."PHONE2", "CUSTOMER"."FAX", "' +
        'CUSTOMER"."OCCUPATION", '
      
        '   "CUSTOMER"."TAXOFFICE", "CUSTOMER"."DETAILS", "CUSTOMER"."DIS' +
        'COUNT", '
      
        '   "CUSTOMER"."SECONDARY_EMAIL", "CUSTOMER"."EMAIL", "CUSTOMER".' +
        '"ORDER", '
      
        '   "CUSTOMER"."COUNTRY", "CUSTOMER"."PAYMETH_ID", "CUSTOMER"."VA' +
        'T_VIES", '
      '   "CUSTOMER"."TYPE", "CUSTOMER"."WITHHOLD_TAX")'
      'values'
      
        '  (:"CUST_ID", :"ALT_CUSTID", :"AFM", :"NAME", :"ADDRESS1", :"AD' +
        'DRESS2", '
      
        '   :"CITY", :"POSTCODE", :"PHONE1", :"PHONE2", :"FAX", :"OCCUPAT' +
        'ION", :"TAXOFFICE", '
      
        '   :"DETAILS", :"DISCOUNT", :"SECONDARY_EMAIL", :"EMAIL", :"ORDE' +
        'R", :"COUNTRY", '
      '   :"PAYMETH_ID", :"VAT_VIES", :"TYPE", :"WITHHOLD_TAX")')
    RefreshSQL.Strings = (
      'Select '
      '  "CUSTOMER"."CUST_ID",'
      '  "CUSTOMER"."ALT_CUSTID",'
      '  "CUSTOMER"."AFM",'
      '  "CUSTOMER"."NAME",'
      '  "CUSTOMER"."ADDRESS1",'
      '  "CUSTOMER"."ADDRESS2",'
      '  "CUSTOMER"."CITY",'
      '  "CUSTOMER"."POSTCODE",'
      '  "CUSTOMER"."PHONE1",'
      '  "CUSTOMER"."PHONE2",'
      '  "CUSTOMER"."FAX",'
      '  "CUSTOMER"."OCCUPATION",'
      '  "CUSTOMER"."TAXOFFICE",'
      '  "CUSTOMER"."DETAILS",'
      '  "CUSTOMER"."DISCOUNT",'
      '  "CUSTOMER"."SECONDARY_EMAIL",'
      '  "CUSTOMER"."EMAIL",'
      '  "CUSTOMER"."ORDER",'
      '  "CUSTOMER"."COUNTRY",'
      '  "CUSTOMER"."PAYMETH_ID",'
      '  "CUSTOMER"."VAT_VIES",'
      '  "CUSTOMER"."TYPE",'
      '  "CUSTOMER"."WITHHOLD_TAX",'
      
        ' (SELECT BALANCE FROM GET_CUSTOMER_BALANCE(CUSTOMER.CUST_ID)) AS' +
        ' BALANCE'
      'from "CUSTOMER" '
      'where'
      '  "CUSTOMER"."CUST_ID" = :"CUST_ID"')
    SelectSQL.Strings = (
      
        'SELECT CUSTOMER.* , (SELECT BALANCE FROM GET_CUSTOMER_BALANCE(CU' +
        'STOMER.CUST_ID)) AS BALANCE'
      ' FROM CUSTOMER'
      'ORDER  BY'
      '"NAME" ASC')
    ModifySQL.Strings = (
      'update "CUSTOMER"'
      'set'
      '  "CUSTOMER"."CUST_ID" = :"CUST_ID",'
      '  "CUSTOMER"."ALT_CUSTID" = :"ALT_CUSTID",'
      '  "CUSTOMER"."AFM" = :"AFM",'
      '  "CUSTOMER"."NAME" = :"NAME",'
      '  "CUSTOMER"."ADDRESS1" = :"ADDRESS1",'
      '  "CUSTOMER"."ADDRESS2" = :"ADDRESS2",'
      '  "CUSTOMER"."CITY" = :"CITY",'
      '  "CUSTOMER"."POSTCODE" = :"POSTCODE",'
      '  "CUSTOMER"."PHONE1" = :"PHONE1",'
      '  "CUSTOMER"."PHONE2" = :"PHONE2",'
      '  "CUSTOMER"."FAX" = :"FAX",'
      '  "CUSTOMER"."OCCUPATION" = :"OCCUPATION",'
      '  "CUSTOMER"."TAXOFFICE" = :"TAXOFFICE",'
      '  "CUSTOMER"."DETAILS" = :"DETAILS",'
      '  "CUSTOMER"."DISCOUNT" = :"DISCOUNT",'
      '  "CUSTOMER"."SECONDARY_EMAIL" = :"SECONDARY_EMAIL",'
      '  "CUSTOMER"."EMAIL" = :"EMAIL",'
      '  "CUSTOMER"."ORDER" = :"ORDER",'
      '  "CUSTOMER"."COUNTRY" = :"COUNTRY",'
      '  "CUSTOMER"."PAYMETH_ID" = :"PAYMETH_ID",'
      '  "CUSTOMER"."VAT_VIES" = :"VAT_VIES",'
      '  "CUSTOMER"."TYPE" = :"TYPE",'
      '  "CUSTOMER"."WITHHOLD_TAX" = :"WITHHOLD_TAX"'
      'where'
      '  "CUSTOMER"."CUST_ID" = :"OLD_CUST_ID"')
    ParamCheck = True
    UniDirectional = False
    GeneratorField.Field = 'CUST_ID'
    GeneratorField.Generator = 'GEN_CUSTOMER_ID'
    Left = 368
    Top = 96
    object DatasetCustomersCUST_ID: TIntegerField
      FieldName = 'CUST_ID'
      Origin = '"CUSTOMER"."CUST_ID"'
      ProviderFlags = [pfInUpdate, pfInWhere, pfInKey]
      Required = True
    end
    object DatasetCustomersALT_CUSTID: TIntegerField
      FieldName = 'ALT_CUSTID'
      Origin = '"CUSTOMER"."ALT_CUSTID"'
    end
    object DatasetCustomersAFM: TIBStringField
      FieldName = 'AFM'
      Origin = '"CUSTOMER"."AFM"'
    end
    object DatasetCustomersNAME: TIBStringField
      FieldName = 'NAME'
      Origin = '"CUSTOMER"."NAME"'
      Required = True
      Size = 50
    end
    object DatasetCustomersADDRESS1: TIBStringField
      FieldName = 'ADDRESS1'
      Origin = '"CUSTOMER"."ADDRESS1"'
      Size = 50
    end
    object DatasetCustomersADDRESS2: TIBStringField
      FieldName = 'ADDRESS2'
      Origin = '"CUSTOMER"."ADDRESS2"'
      Size = 50
    end
    object DatasetCustomersCITY: TIBStringField
      FieldName = 'CITY'
      Origin = '"CUSTOMER"."CITY"'
      Size = 30
    end
    object DatasetCustomersPOSTCODE: TIBStringField
      FieldName = 'POSTCODE'
      Origin = '"CUSTOMER"."POSTCODE"'
      Size = 10
    end
    object DatasetCustomersOCCUPATION: TIBStringField
      FieldName = 'OCCUPATION'
      Origin = '"CUSTOMER"."OCCUPATION"'
      Size = 120
    end
    object DatasetCustomersTAXOFFICE: TIBStringField
      FieldName = 'TAXOFFICE'
      Origin = '"CUSTOMER"."TAXOFFICE"'
      Size = 30
    end
    object DatasetCustomersDETAILS: TWideMemoField
      FieldName = 'DETAILS'
      Origin = '"CUSTOMER"."DETAILS"'
      ProviderFlags = [pfInUpdate]
      BlobType = ftMemo
      Size = 8
    end
    object DatasetCustomersDISCOUNT: TIBBCDField
      FieldName = 'DISCOUNT'
      Origin = '"CUSTOMER"."DISCOUNT"'
      Precision = 18
      Size = 2
    end
    object DatasetCustomersEMAIL: TIBStringField
      FieldName = 'EMAIL'
      Origin = '"CUSTOMER"."EMAIL"'
      Size = 120
    end
    object DatasetCustomersORDER: TIntegerField
      FieldName = 'ORDER'
      Origin = '"CUSTOMER"."ORDER"'
    end
    object DatasetCustomersCOUNTRY: TIBStringField
      FieldName = 'COUNTRY'
      Origin = '"CUSTOMER"."COUNTRY"'
      Size = 40
    end
    object DatasetCustomersPAYMETH_ID: TIntegerField
      FieldName = 'PAYMETH_ID'
      Origin = '"CUSTOMER"."PAYMETH_ID"'
    end
    object DatasetCustomersBALANCE: TIBBCDField
      FieldName = 'BALANCE'
      ProviderFlags = []
      currency = True
      Precision = 18
      Size = 2
    end
    object DatasetCustomersVAT_VIES: TIBStringField
      FieldName = 'VAT_VIES'
      Origin = '"CUSTOMER"."VAT_VIES"'
      Size = 30
    end
    object DatasetCustomersSECONDARY_EMAIL: TIBStringField
      FieldName = 'SECONDARY_EMAIL'
      Origin = '"CUSTOMER"."SECONDARY_EMAIL"'
      Size = 120
    end
    object DatasetCustomersPHONE1: TIBStringField
      FieldName = 'PHONE1'
      Origin = '"CUSTOMER"."PHONE1"'
      Size = 30
    end
    object DatasetCustomersPHONE2: TIBStringField
      FieldName = 'PHONE2'
      Origin = '"CUSTOMER"."PHONE2"'
      Size = 30
    end
    object DatasetCustomersFAX: TIBStringField
      FieldName = 'FAX'
      Origin = '"CUSTOMER"."FAX"'
      Size = 30
    end
    object DatasetCustomersTYPE: TIBStringField
      FieldName = 'TYPE'
      Origin = '"CUSTOMER"."TYPE"'
      Size = 60
    end
    object DatasetCustomersWITHHOLD_TAX: TIntegerField
      FieldName = 'WITHHOLD_TAX'
      Origin = '"CUSTOMER"."WITHHOLD_TAX"'
    end
  end
  object QueryMonthGroup: TIBQuery
    Database = FrmMain.database
    Transaction = FrmMain.IBTransaction1
    BeforeOpen = QueryMonthGroupBeforeOpen
    BufferChunks = 1000
    CachedUpdates = False
    DataSource = DSCustomers
    ParamCheck = True
    SQL.Strings = (
      'SELECT DISTINCT TRIM(CASE EXTRACT(MONTH FROM I.invdate)'
      '        WHEN 1 THEN '#39#921#945#957#959#965#940#961#953#959#962#39
      '        WHEN 2 THEN '#39#934#949#946#961#959#965#940#961#953#959#962#39
      '        WHEN 3 THEN '#39#924#940#961#964#953#959#962#39
      '        WHEN 4 THEN '#39#913#960#961#943#955#953#959#962#39
      '        WHEN 5 THEN '#39#924#945#943#959#962#39
      '        WHEN 6 THEN '#39#921#959#973#957#953#959#962#39
      '        WHEN 7 THEN '#39#921#959#973#955#953#959#962#39
      '        WHEN 8 THEN '#39#913#973#947#959#965#963#964#959#962#39
      '        WHEN 9 THEN '#39#931#949#960#964#941#956#946#961#953#959#962#39
      '        WHEN 10 THEN '#39#927#954#964#969#946#961#953#959#962#39
      '        WHEN 11 THEN '#39#925#959#941#956#946#961#953#959#962#39
      '        WHEN 12 THEN '#39#916#949#954#941#956#946#961#953#959#962#39
      
        '        END) || '#39' '#39' || EXTRACT(YEAR FROM I.INVDATE) AS MONTH_DAT' +
        'E,'
      
        '        cast('#39'01-'#39'||EXTRACT(MONTH FROM I.invdate) ||'#39'-'#39'|| EXTRAC' +
        'T(YEAR FROM I.INVDATE) as date) AS FIRSTDATE,'
      '        COALESCE((SELECT SUM(INV.PRICEWVAT) FROM INVOICE INV'
      
        '                LEFT OUTER JOIN PAYMENT_METHOD M ON INV.paymeth_' +
        'id = M.method_id'
      
        '                    WHERE INV.CUST_ID = :CUST_ID AND M.due_days ' +
        '=0 AND EXTRACT(MONTH FROM INV.invdate) = EXTRACT(MONTH FROM I.in' +
        'vdate) AND'
      
        '                            EXTRACT(YEAR FROM INV.invdate) = EXT' +
        'RACT(YEAR FROM I.invdate)),0) AS SUM_0DUE,'
      '        COALESCE((SELECT SUM(INV.PRICEWVAT) FROM INVOICE INV'
      
        '                LEFT OUTER JOIN PAYMENT_METHOD M ON INV.paymeth_' +
        'id = M.method_id'
      
        '                    WHERE INV.CUST_ID = :CUST_ID AND M.due_days ' +
        '> 0  AND EXTRACT(MONTH FROM INV.invdate) = EXTRACT(MONTH FROM I.' +
        'invdate) AND'
      
        '                            EXTRACT(YEAR FROM INV.invdate) = EXT' +
        'RACT(YEAR FROM I.invdate)),0) AS SUM_DUE'
      'FROM INVOICE I'
      'WHERE CUST_ID = :CUST_ID'
      'order by firstdate desc')
    Left = 600
    Top = 439
    ParamData = <
      item
        DataType = ftUnknown
        Name = 'CUST_ID'
        ParamType = ptUnknown
      end
      item
        DataType = ftUnknown
        Name = 'CUST_ID'
        ParamType = ptUnknown
      end
      item
        DataType = ftUnknown
        Name = 'CUST_ID'
        ParamType = ptUnknown
      end>
    object QueryMonthGroupMONTH_DATE: TIBStringField
      FieldName = 'MONTH_DATE'
      ProviderFlags = []
      Size = 18
    end
    object QueryMonthGroupFIRSTDATE: TDateField
      FieldName = 'FIRSTDATE'
      ProviderFlags = []
    end
    object QueryMonthGroupSUM_0DUE: TIBBCDField
      FieldName = 'SUM_0DUE'
      ProviderFlags = []
      currency = True
      Precision = 18
      Size = 2
    end
    object QueryMonthGroupSUM_DUE: TIBBCDField
      FieldName = 'SUM_DUE'
      ProviderFlags = []
      currency = True
      Precision = 18
      Size = 2
    end
  end
  object DSMonthGroup: TDataSource
    AutoEdit = False
    DataSet = QueryMonthGroup
    Left = 568
    Top = 431
  end
end
