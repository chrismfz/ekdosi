object FrmAddPayment: TFrmAddPayment
  Left = 0
  Top = 0
  Caption = #917#943#963#960#961#945#958#951
  ClientHeight = 309
  ClientWidth = 360
  Color = clBtnFace
  Font.Charset = DEFAULT_CHARSET
  Font.Color = clWindowText
  Font.Height = -11
  Font.Name = 'Tahoma'
  Font.Style = []
  FormStyle = fsMDIChild
  OldCreateOrder = False
  Visible = True
  DesignSize = (
    360
    309)
  PixelsPerInch = 96
  TextHeight = 13
  object Label2: TLabel
    Left = 58
    Top = 26
    Width = 53
    Height = 16
    Hint = #928#949#961#953#947#961#945#966#942' '#956#945#952#942#956#945#964#959#962
    Alignment = taRightJustify
    Caption = #917#960#969#957#965#956#943#945
    Font.Charset = GREEK_CHARSET
    Font.Color = clWindowText
    Font.Height = -13
    Font.Name = 'Tahoma'
    Font.Style = []
    ParentFont = False
  end
  object Label1: TLabel
    Left = 83
    Top = 54
    Width = 28
    Height = 16
    Hint = #928#949#961#953#947#961#945#966#942' '#956#945#952#942#956#945#964#959#962
    Alignment = taRightJustify
    Caption = #913#934#924
    Font.Charset = GREEK_CHARSET
    Font.Color = clWindowText
    Font.Height = -13
    Font.Name = 'Tahoma'
    Font.Style = []
    ParentFont = False
  end
  object Label7: TLabel
    Left = 25
    Top = 82
    Width = 86
    Height = 16
    Hint = #916#961#945#963#964#951#961#953#972#964#951#964#945
    Alignment = taRightJustify
    Caption = #916#961#945#963#964#951#961#953#972#964#951#964#945
    Font.Charset = GREEK_CHARSET
    Font.Color = clWindowText
    Font.Height = -13
    Font.Name = 'Tahoma'
    Font.Style = []
    ParentFont = False
  end
  object Label4: TLabel
    Left = 46
    Top = 106
    Width = 65
    Height = 16
    Hint = #928#949#961#953#947#961#945#966#942' '#956#945#952#942#956#945#964#959#962
    Alignment = taRightJustify
    Caption = #919#956#949#961#959#956#951#957#943#945
    Font.Charset = GREEK_CHARSET
    Font.Color = clWindowText
    Font.Height = -13
    Font.Name = 'Tahoma'
    Font.Style = []
    ParentFont = False
  end
  object lblOldBalance: TLabel
    Left = 203
    Top = 174
    Width = 110
    Height = 23
    Alignment = taRightJustify
    AutoSize = False
    Caption = '0,00 '#8364
    Font.Charset = GREEK_CHARSET
    Font.Color = clWindowText
    Font.Height = -19
    Font.Name = 'Tahoma'
    Font.Style = []
    ParentFont = False
  end
  object Label11: TLabel
    Left = 11
    Top = 174
    Width = 202
    Height = 23
    Hint = #928#949#961#953#947#961#945#966#942' '#956#945#952#942#956#945#964#959#962
    Alignment = taRightJustify
    Caption = #928#961#959#951#947#959#973#956#949#957#959' '#933#960#972#955#959#953#960#959':'
    Font.Charset = GREEK_CHARSET
    Font.Color = clWindowText
    Font.Height = -19
    Font.Name = 'Tahoma'
    Font.Style = []
    ParentFont = False
  end
  object lblNewBalance: TLabel
    Left = 203
    Top = 211
    Width = 110
    Height = 23
    Alignment = taRightJustify
    AutoSize = False
    Caption = '0,00 '#8364
    Font.Charset = GREEK_CHARSET
    Font.Color = clWindowText
    Font.Height = -19
    Font.Name = 'Tahoma'
    Font.Style = []
    ParentFont = False
  end
  object Label3: TLabel
    Left = 92
    Top = 211
    Width = 121
    Height = 23
    Hint = #928#949#961#953#947#961#945#966#942' '#956#945#952#942#956#945#964#959#962
    Alignment = taRightJustify
    Caption = #925#941#959' '#933#960#972#955#959#953#960#959':'
    Font.Charset = GREEK_CHARSET
    Font.Color = clWindowText
    Font.Height = -19
    Font.Name = 'Tahoma'
    Font.Style = []
    ParentFont = False
  end
  object Label5: TLabel
    Left = 59
    Top = 134
    Width = 52
    Height = 16
    Hint = #928#949#961#953#947#961#945#966#942' '#956#945#952#942#956#945#964#959#962
    Alignment = taRightJustify
    Caption = #917#943#963#960#961#945#958#951
    Font.Charset = GREEK_CHARSET
    Font.Color = clWindowText
    Font.Height = -13
    Font.Name = 'Tahoma'
    Font.Style = []
    ParentFont = False
  end
  object editName: TJvDotNetEdit
    Left = 117
    Top = 24
    Width = 204
    Height = 20
    Hint = #917#953#963#940#947#949#964#949' '#949#960#969#957#965#956#943#945' ('#928#945#964#942#963#964#949' F9 '#947#953#945' '#949#960#953#955#959#947#942' '#945#960#972' '#955#943#963#964#945')'
    DotNetHighlighting = True
    Flat = False
    ParentFlat = False
    AutoSize = False
    CharCase = ecUpperCase
    Font.Charset = DEFAULT_CHARSET
    Font.Color = clWindowText
    Font.Height = -13
    Font.Name = 'Arial'
    Font.Style = []
    ParentFont = False
    TabOrder = 0
    Text = ''
    OnChange = editNameChange
    OnKeyDown = editNameKeyDown
  end
  object editVatNo: TJvDotNetEdit
    Left = 117
    Top = 50
    Width = 204
    Height = 20
    Hint = #917#953#963#940#947#949#964#949' '#913#934#924' ('#928#945#964#942#963#964#949' F9 '#947#953#945' '#949#960#953#955#959#947#942' '#945#960#959' '#955#943#963#964#945')'
    DotNetHighlighting = True
    Flat = False
    ParentFlat = False
    AutoSize = False
    CharCase = ecUpperCase
    Font.Charset = DEFAULT_CHARSET
    Font.Color = clWindowText
    Font.Height = -13
    Font.Name = 'Arial'
    Font.Style = []
    ParentFont = False
    TabOrder = 1
    Text = ''
    OnChange = editVatNoChange
    OnKeyDown = editVatNoKeyDown
    OnKeyPress = editVatNoKeyPress
  end
  object editOccupation: TJvDotNetEdit
    Left = 117
    Top = 78
    Width = 204
    Height = 20
    Hint = #916#961#945#963#964#951#961#953#972#964#951#964#945
    DotNetHighlighting = True
    Flat = False
    ParentFlat = False
    AutoSize = False
    Font.Charset = DEFAULT_CHARSET
    Font.Color = clWindowText
    Font.Height = -13
    Font.Name = 'Arial'
    Font.Style = []
    ParentFont = False
    ReadOnly = True
    TabOrder = 2
    Text = ''
  end
  object editDate: TJvDBDateEdit
    Left = 117
    Top = 104
    Width = 154
    Height = 22
    Hint = #919#956#949#961#959#956#951#957#943#945' '#941#954#948#959#963#951#962' '#960#945#961#945#963#964#945#964#953#954#959#973
    DotNetHighlighting = True
    AutoSize = False
    DataField = 'PAY_DATE'
    DataSource = DSPayments
    Flat = False
    ParentFlat = False
    Font.Charset = DEFAULT_CHARSET
    Font.Color = clWindowText
    Font.Height = -12
    Font.Name = 'Tahoma'
    Font.Style = []
    ParentFont = False
    ShowNullDate = False
    TabOrder = 3
  end
  object editPayment: TJvValidateEdit
    Left = 117
    Top = 132
    Width = 99
    Height = 21
    AutoSize = False
    CriticalPoints.MaxValueIncluded = False
    CriticalPoints.MinValueIncluded = False
    DisplayFormat = dfCurrency
    DecimalPlaces = 2
    Font.Charset = DEFAULT_CHARSET
    Font.Color = clWindowText
    Font.Height = -12
    Font.Name = 'Tahoma'
    Font.Style = []
    ParentFont = False
    TabOrder = 4
    OnExit = editPaymentExit
  end
  object StatusBar: TStatusBar
    Left = 0
    Top = 291
    Width = 360
    Height = 18
    AutoHint = True
    Panels = <
      item
        Width = 50
      end>
  end
  object JvDotNetButton1: TJvDotNetButton
    Left = 133
    Top = 253
    Width = 99
    Height = 25
    Hint = #922#945#964#945#967#974#961#951#963#951' '#960#945#961#945#963#964#945#964#953#954#959#973' '#954#945#953' '#949#954#964#973#960#969#963#951
    Anchors = [akRight, akBottom]
    Caption = #922#945#964#945#967#974#961#951#963#951
    TabOrder = 5
    OnClick = JvDotNetButton1Click
  end
  object JvDotNetButton2: TJvDotNetButton
    Left = 253
    Top = 253
    Width = 99
    Height = 25
    Hint = #913#954#973#961#969#963#951' '#954#945#964#945#967#974#961#951#963#951#962
    Anchors = [akRight, akBottom]
    Caption = #913#954#973#961#969#963#951
    TabOrder = 6
    OnClick = JvDotNetButton2Click
  end
  object QryCustomer: TIBQuery
    Database = FrmMain.database
    Transaction = FrmMain.IBTransaction1
    BufferChunks = 1000
    CachedUpdates = False
    ParamCheck = True
    SQL.Strings = (
      'SELECT * FROM CUSTOMER'
      'WHERE CUST_ID = :CUSTID')
    Left = 284
    Top = 104
    ParamData = <
      item
        DataType = ftUnknown
        Name = 'CUSTID'
        ParamType = ptUnknown
      end>
    object QryCustomerCUST_ID: TIntegerField
      FieldName = 'CUST_ID'
      Origin = '"CUSTOMER"."CUST_ID"'
      ProviderFlags = [pfInUpdate, pfInWhere, pfInKey]
      Required = True
    end
    object QryCustomerAFM: TIBStringField
      FieldName = 'AFM'
      Origin = '"CUSTOMER"."AFM"'
      Required = True
    end
    object QryCustomerNAME: TIBStringField
      FieldName = 'NAME'
      Origin = '"CUSTOMER"."NAME"'
      Required = True
      Size = 50
    end
    object QryCustomerADDRESS1: TIBStringField
      FieldName = 'ADDRESS1'
      Origin = '"CUSTOMER"."ADDRESS1"'
      Size = 50
    end
    object QryCustomerADDRESS2: TIBStringField
      FieldName = 'ADDRESS2'
      Origin = '"CUSTOMER"."ADDRESS2"'
      Size = 50
    end
    object QryCustomerCITY: TIBStringField
      FieldName = 'CITY'
      Origin = '"CUSTOMER"."CITY"'
      Size = 30
    end
    object QryCustomerPOSTCODE: TIBStringField
      FieldName = 'POSTCODE'
      Origin = '"CUSTOMER"."POSTCODE"'
      Size = 10
    end
    object QryCustomerPHONE1: TIBStringField
      FieldName = 'PHONE1'
      Origin = '"CUSTOMER"."PHONE1"'
      Size = 15
    end
    object QryCustomerPHONE2: TIBStringField
      FieldName = 'PHONE2'
      Origin = '"CUSTOMER"."PHONE2"'
      Size = 15
    end
    object QryCustomerFAX: TIBStringField
      FieldName = 'FAX'
      Origin = '"CUSTOMER"."FAX"'
      Size = 15
    end
    object QryCustomerOCCUPATION: TIBStringField
      FieldName = 'OCCUPATION'
      Origin = '"CUSTOMER"."OCCUPATION"'
      Size = 60
    end
    object QryCustomerTAXOFFICE: TIBStringField
      FieldName = 'TAXOFFICE'
      Origin = '"CUSTOMER"."TAXOFFICE"'
      Size = 30
    end
    object QryCustomerDETAILS: TMemoField
      FieldName = 'DETAILS'
      Origin = '"CUSTOMER"."DETAILS"'
      ProviderFlags = [pfInUpdate]
      BlobType = ftMemo
      Size = 8
    end
    object QryCustomerDISCOUNT: TIBBCDField
      FieldName = 'DISCOUNT'
      Origin = '"CUSTOMER"."DISCOUNT"'
      Precision = 18
      Size = 2
    end
    object QryCustomerEMAIL: TIBStringField
      FieldName = 'EMAIL'
      Origin = '"CUSTOMER"."EMAIL"'
      Size = 120
    end
  end
  object DatasetPayment: TIBDataSet
    Database = FrmMain.database
    Transaction = FrmMain.IBTransaction1
    AutoCalcFields = False
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
      'SELECT * FROM PAYMENT')
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
    Left = 284
    Top = 136
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
  object DSPayments: TDataSource
    DataSet = DatasetPayment
    Left = 316
    Top = 136
  end
end
