object FrmInvoiceSend: TFrmInvoiceSend
  Left = 0
  Top = 0
  Caption = #931#965#947#954#949#957#964#961#969#964#953#954#972' '#948#949#955#964#943#959' '#945#960#959#963#964#959#955#942#962
  ClientHeight = 450
  ClientWidth = 483
  Color = clBtnFace
  Font.Charset = DEFAULT_CHARSET
  Font.Color = clWindowText
  Font.Height = -11
  Font.Name = 'Tahoma'
  Font.Style = []
  FormStyle = fsMDIChild
  OldCreateOrder = False
  Visible = True
  PixelsPerInch = 96
  TextHeight = 13
  object JvPanel1: TJvPanel
    Left = 0
    Top = 0
    Width = 483
    Height = 33
    Align = alTop
    TabOrder = 0
    ExplicitWidth = 484
    object Label2: TLabel
      Left = 11
      Top = 9
      Width = 87
      Height = 18
      Alignment = taRightJustify
      Caption = #919#956#949#961#959#956#951#957#943#945
      Font.Charset = GREEK_CHARSET
      Font.Color = clBlack
      Font.Height = -15
      Font.Name = 'Tahoma'
      Font.Style = [fsBold]
      ParentFont = False
    end
    object Label1: TLabel
      Left = 272
      Top = 9
      Width = 32
      Height = 18
      Alignment = taRightJustify
      Caption = #911#961#945
      Font.Charset = GREEK_CHARSET
      Font.Color = clBlack
      Font.Height = -15
      Font.Name = 'Tahoma'
      Font.Style = [fsBold]
      ParentFont = False
    end
    object lblWarning: TLabel
      Left = 1
      Top = 11
      Width = 481
      Height = 21
      Align = alBottom
      Alignment = taCenter
      AutoSize = False
      Font.Charset = GREEK_CHARSET
      Font.Color = clMaroon
      Font.Height = -15
      Font.Name = 'Tahoma'
      Font.Style = [fsBold]
      ParentFont = False
      Visible = False
      ExplicitTop = 8
      ExplicitWidth = 482
    end
    object editDate: TJvDateEdit
      Left = 104
      Top = 8
      Width = 121
      Height = 21
      Hint = #919#956#949#961#959#956#951#957#943#945' '#947#953#945' '#960#961#959#946#959#955#942' '#960#945#961#945#963#964#945#964#953#954#959#973
      DotNetHighlighting = True
      AutoSize = False
      CalendarHints.Strings = (
        #917#960#953#955#941#958#964#949' '#951#956#949#961#959#956#951#957#943#945)
      CheckOnExit = True
      DefaultToday = True
      DialogTitle = #917#960#953#955#941#958#964#949' '#951#956#949#961#959#956#951#957#943#945
      Font.Charset = DEFAULT_CHARSET
      Font.Color = clWindowText
      Font.Height = -11
      Font.Name = 'Tahoma'
      Font.Style = [fsBold]
      ParentFont = False
      ShowNullDate = False
      TabOrder = 0
      OnChange = editDateChange
    end
    object editTime: TJvTimeEdit
      Left = 310
      Top = 8
      Width = 121
      Height = 21
      Font.Charset = DEFAULT_CHARSET
      Font.Color = clWindowText
      Font.Height = -11
      Font.Name = 'Tahoma'
      Font.Style = [fsBold]
      ParentFont = False
      TabOrder = 1
    end
  end
  object StatusBar1: TStatusBar
    Left = 0
    Top = 434
    Width = 483
    Height = 16
    AutoHint = True
    Panels = <
      item
        Width = 50
      end>
    ExplicitWidth = 484
  end
  object JvPanel2: TJvPanel
    Left = 0
    Top = 33
    Width = 483
    Height = 360
    Align = alClient
    TabOrder = 2
    ExplicitWidth = 484
    object GridItems: TcxGrid
      Left = 1
      Top = 1
      Width = 481
      Height = 358
      Align = alClient
      BorderStyle = cxcbsNone
      TabOrder = 0
      DragOpening = False
      LookAndFeel.Kind = lfStandard
      LookAndFeel.NativeStyle = True
      ExplicitTop = 2
      object ViewItems: TcxGridDBTableView
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
        DataController.DataSource = DSProductQty
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
        object ViewItemsBARCODE: TcxGridDBColumn
          Caption = 'Barcode'
          DataBinding.FieldName = 'BARCODE'
        end
        object ViewItemsDESCRIPTION_SHORT: TcxGridDBColumn
          Caption = #928#949#961#953#947#961#945#966#942
          DataBinding.FieldName = 'DESCRIPTION_SHORT'
          Width = 242
        end
        object ViewItemsQTY: TcxGridDBColumn
          Caption = #928#959#963#972#964#951#964#945
          DataBinding.FieldName = 'QTY'
        end
      end
      object GridItemsLevel1: TcxGridLevel
        GridView = ViewItems
      end
    end
  end
  object JvPanel3: TJvPanel
    Left = 0
    Top = 393
    Width = 483
    Height = 41
    Align = alBottom
    TabOrder = 3
    DesignSize = (
      483
      41)
    object cmdAccept: TJvDotNetButton
      Left = 264
      Top = 6
      Width = 99
      Height = 25
      Hint = #922#945#964#945#967#974#961#951#963#951' '#960#945#961#945#963#964#945#964#953#954#959#973' '#954#945#953' '#949#954#964#973#960#969#963#951
      Anchors = [akRight, akBottom]
      Caption = #922#945#964#945#967#974#961#951#963#951
      TabOrder = 0
      OnClick = cmdAcceptClick
      ExplicitLeft = 265
    end
    object JvDotNetButton2: TJvDotNetButton
      Left = 369
      Top = 6
      Width = 99
      Height = 25
      Hint = #913#954#973#961#969#963#951' '#954#945#964#945#967#974#961#951#963#951#962
      Anchors = [akRight, akBottom]
      Caption = #913#954#973#961#969#963#951
      TabOrder = 1
      OnClick = JvDotNetButton2Click
      ExplicitLeft = 370
    end
    object cmdPrint: TJvDotNetButton
      Left = 12
      Top = 5
      Width = 99
      Height = 25
      Hint = #917#960#945#957#949#954#964#973#960#969#963#951' '#960#945#961#945#963#964#945#964#953#954#959#973
      Caption = #917#960#945#957#949#954#964#973#960#969#963#951
      TabOrder = 2
      Visible = False
    end
  end
  object DSProductQty: TDataSource
    DataSet = DatasetProductQty
    Left = 48
    Top = 72
  end
  object DatasetProductQty: TIBDataSet
    Database = FrmMain.database
    Transaction = FrmMain.IBTransaction1
    AutoCalcFields = False
    AfterPost = DatasetProductQtyAfterPost
    BufferChunks = 1000
    CachedUpdates = False
    SelectSQL.Strings = (
      'SELECT * FROM SHOW_CUMULATIVE_INVOICE (:INVOICEID)'
      'ORDER BY '
      'BARCODE ASC')
    ModifySQL.Strings = (
      
        'EXECUTE PROCEDURE UPDATE_ROWS_CINVOICE(:PRODUCT_ID, :INVOICE_ID,' +
        ' :QTY)')
    ParamCheck = True
    UniDirectional = False
    Left = 48
    Top = 104
    object DatasetProductQtyPRODUCT_ID: TIntegerField
      FieldName = 'PRODUCT_ID'
      Origin = '"SHOW_CUMULATIVE_INVOICE"."PRODUCT_ID"'
      ReadOnly = True
    end
    object DatasetProductQtyBARCODE: TIntegerField
      FieldName = 'BARCODE'
      Origin = '"SHOW_CUMULATIVE_INVOICE"."BARCODE"'
      ReadOnly = True
    end
    object DatasetProductQtyDESCRIPTION_SHORT: TIBStringField
      FieldName = 'DESCRIPTION_SHORT'
      Origin = '"PRODUCT"."DESCRIPTION_SHORT"'
      ReadOnly = True
      Size = 60
    end
    object DatasetProductQtyINVOICE_ID: TIntegerField
      FieldName = 'INVOICE_ID'
      Origin = '"SHOW_CUMULATIVE_INVOICE"."INVOICE_ID"'
    end
    object DatasetProductQtyQTY: TIBBCDField
      FieldName = 'QTY'
      Origin = '"SHOW_CUMULATIVE_INVOICE"."QTY"'
      Precision = 9
      Size = 3
    end
  end
  object QryInvoice: TIBQuery
    Database = FrmMain.database
    Transaction = FrmMain.IBTransaction1
    BufferChunks = 1000
    CachedUpdates = False
    ParamCheck = True
    SQL.Strings = (
      'SELECT * FROM INVOICE WHERE INVOICE_ID  = :INVOICEID')
    Left = 52
    Top = 152
    ParamData = <
      item
        DataType = ftUnknown
        Name = 'INVOICEID'
        ParamType = ptUnknown
      end>
    object QryInvoiceINVOICE_ID: TIntegerField
      FieldName = 'INVOICE_ID'
      Origin = '"INVOICE"."INVOICE_ID"'
      ProviderFlags = [pfInUpdate, pfInWhere, pfInKey]
      Required = True
    end
    object QryInvoiceINVCODE: TIBStringField
      FieldName = 'INVCODE'
      Origin = '"INVOICE"."INVCODE"'
      Required = True
      Size = 15
    end
    object QryInvoiceCUST_ID: TIntegerField
      FieldName = 'CUST_ID'
      Origin = '"INVOICE"."CUST_ID"'
    end
    object QryInvoiceINVTYPE: TIBStringField
      FieldName = 'INVTYPE'
      Origin = '"INVOICE"."INVTYPE"'
      Size = 6
    end
    object QryInvoiceINVDATE: TDateField
      FieldName = 'INVDATE'
      Origin = '"INVOICE"."INVDATE"'
    end
    object QryInvoicePRINTED: TSmallintField
      FieldName = 'PRINTED'
      Origin = '"INVOICE"."PRINTED"'
    end
    object QryInvoiceDELIVERYDATE: TDateField
      FieldName = 'DELIVERYDATE'
      Origin = '"INVOICE"."DELIVERYDATE"'
    end
    object QryInvoiceDISTRAIM_ID: TIntegerField
      FieldName = 'DISTRAIM_ID'
      Origin = '"INVOICE"."DISTRAIM_ID"'
    end
    object QryInvoiceDELMETHOD_ID: TIntegerField
      FieldName = 'DELMETHOD_ID'
      Origin = '"INVOICE"."DELMETHOD_ID"'
    end
    object QryInvoicePAYMETH_ID: TIntegerField
      FieldName = 'PAYMETH_ID'
      Origin = '"INVOICE"."PAYMETH_ID"'
    end
    object QryInvoiceDISCOUNT: TIBBCDField
      FieldName = 'DISCOUNT'
      Origin = '"INVOICE"."DISCOUNT"'
      Precision = 18
      Size = 2
    end
    object QryInvoicePRICE: TIBBCDField
      FieldName = 'PRICE'
      Origin = '"INVOICE"."PRICE"'
      Precision = 18
      Size = 2
    end
    object QryInvoicePRICEWVAT: TIBBCDField
      FieldName = 'PRICEWVAT'
      Origin = '"INVOICE"."PRICEWVAT"'
      Precision = 18
      Size = 2
    end
    object QryInvoiceNOTES: TMemoField
      FieldName = 'NOTES'
      Origin = '"INVOICE"."NOTES"'
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
end
